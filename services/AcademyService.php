<?php
declare(strict_types=1);

final class AcademyService
{
    public static function uniqueCode(string $name): string
    {
        $words = preg_split('/\s+/', strtoupper(preg_replace('/[^A-Za-z ]/', '', $name) ?: 'ACD')) ?: ['ACD'];
        $base = '';
        foreach ($words as $w) {
            $base .= substr($w, 0, 1);
        }
        $base = substr(str_pad($base, 3, substr($words[0] ?? 'X', 1, 2) . 'X'), 0, 4);
        $code = $base;
        for ($i = 2; DB::val('SELECT 1 FROM academies WHERE code = ?', [$code]); $i++) {
            $code = substr($base, 0, 3) . $i;
        }
        return $code;
    }

    public static function uniqueSlug(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'academy';
        $base = substr($base, 0, 100);
        $slug = $base;
        for ($i = 2; DB::val('SELECT 1 FROM academies WHERE slug = ?', [$slug]); $i++) {
            $slug = "$base-$i";
        }
        return $slug;
    }

    /**
     * Creates owner login + academy + subscription in one transaction.
     * Used by the public registration page and by Super Admin "Add Owner".
     */
    public static function createOwner(array $in, ?Ctx $by, bool $selfService): array
    {
        $cc = Phone::code($in['country_code'] ?? '+91');
        $d = Validator::make($in, [
            'owner_name'   => 'required|max:120',
            'mobile'       => 'required|phone',
            'email'        => 'email|max:160',
            'academy_name' => 'required|max:160',
            'city'         => 'max:80',
            'category'     => 'max:80',
            'package_id'   => 'int',
            'password'     => $selfService ? 'required|min:8' : 'min:8',
            'address'      => 'max:500',
        ], ['owner_name' => 'Your name', 'academy_name' => 'Academy name'])->validated();
        $mobile = Phone::normalize($d['mobile'], $cc);
        if (!$mobile) {
            throw ApiException::validation(['mobile' => 'Enter a valid mobile number.']);
        }
        if (DB::val("SELECT 1 FROM users WHERE role = 'owner' AND country_code = ? AND mobile = ?", [$cc, $mobile])) {
            throw ApiException::validation(['mobile' => 'An academy owner account already exists with this mobile number. Please log in.']);
        }
        $packageId = (int) ($d['package_id'] ?? 0) ?: Settings::int('default_trial_package_id', 2);
        $pkg = DB::one("SELECT * FROM packages WHERE id = ? AND status = 'active'", [$packageId]);
        if (!$pkg) {
            throw ApiException::validation(['package_id' => 'Choose a valid plan.']);
        }
        if (!empty($d['password'])) {
            Auth::validatePassword($d['password']);
        }
        $result = DB::tx(static function () use ($d, $in, $cc, $mobile, $pkg, $by, $selfService) {
            $aid = DB::insert('academies', [
                'code'           => self::uniqueCode($d['academy_name']),
                'slug'           => self::uniqueSlug($d['academy_name']),
                'name'           => $d['academy_name'],
                'city'           => $d['city'] ?? null,
                'address'        => $d['address'] ?? null,
                'mobile'         => $mobile,
                'whatsapp'       => $mobile,
                'email'          => $d['email'] ?? null,
                'contact_person' => $d['owner_name'],
                'business_details' => $d['category'] ?? null,
            ]);
            $uid = AccountService::ensureUser($aid, 'owner', $d['owner_name'], $cc, $mobile, $d['email'] ?? null, $d['password'] ?? null);
            DB::update('academies', ['owner_user_id' => $uid], 'id = ?', [$aid]);
            $status = $in['subscription_status'] ?? ($pkg['trial_days'] > 0 ? 'trial' : 'active');
            if (!in_array($status, ['trial', 'active'], true)) {
                $status = 'trial';
            }
            Subscription::assign($aid, (int) $pkg['id'], $selfService ? ($pkg['trial_days'] > 0 ? 'trial' : 'active') : $status, $in['starts_on'] ?? null, $in['ends_on'] ?? null, (int) $pkg['price_paise'], $by, $selfService ? 'Self registration' : 'Created by Super Admin');
            Audit::log($by, 'owner.create', 'academies', $aid, null, ['academy' => $d['academy_name'], 'owner' => $d['owner_name'], 'self' => $selfService], $aid);
            return ['academy_id' => $aid, 'user_id' => $uid];
        });
        $result['invite'] = empty($d['password']) ? AccountService::invite($result['user_id']) : null;
        return $result;
    }
}

<?php
declare(strict_types=1);

/**
 * The Class Admin's simple WhatsApp choice: Automatic (official WhatsApp Business API), Manual (click-to-send)
 * or Off. The plan decides what is possible; the academy can only choose the same or a simpler mode.
 * No credentials or technical details are exposed here – the Super Admin manages the API connection.
 */
final class WhatsAppChoice
{
    public static function state(int $academyId): array
    {
        $f = Feature::forAcademy($academyId);
        $plan = $f['whatsapp_package_mode'] ?? $f['whatsapp_mode'];
        $cfg = DB::one('SELECT mode, phone_number_id, access_token_enc FROM whatsapp_configurations WHERE id = 1') ?? ['mode' => 'off'];
        $apiReady = ($cfg['mode'] ?? 'off') === 'test' || (($cfg['mode'] ?? 'off') === 'live' && !empty($cfg['phone_number_id']) && !empty($cfg['access_token_enc']));
        $pref = (string) (Settings::academy($academyId)['whatsapp_preference'] ?? '');
        $current = $f['whatsapp_mode'];
        $note = null;
        if ($current === 'api' && !$apiReady) {
            $note = 'Automatic WhatsApp is not connected yet by the ClassNex team. Until it is, choose Manual WhatsApp so your messages still reach parents.';
        }
        return [
            'current' => $current,          // off | manual | api (what the academy uses now)
            'plan' => $plan,                // highest mode in the plan
            'preference' => $pref,          // '' = as the plan allows
            'api_ready' => $apiReady,
            'note' => $note,
            'options' => [
                ['key' => 'manual', 'title' => 'Manual WhatsApp', 'text' => 'WhatsApp opens with a ready message. You press Send.', 'available' => $plan !== 'off',
                    'reason' => $plan === 'off' ? 'WhatsApp is not included in your plan.' : null],
                ['key' => 'api', 'title' => 'Automatic WhatsApp', 'text' => 'Messages are sent automatically through the official WhatsApp Business account.', 'available' => $plan === 'api',
                    'reason' => $plan !== 'api' ? 'Not included in your plan. Upgrade in Subscription to send automatically.' : (!$apiReady ? 'Waiting for the ClassNex team to connect WhatsApp.' : null)],
                ['key' => 'off', 'title' => 'Turned off', 'text' => 'No WhatsApp messages. Parents still get app notifications.', 'available' => true, 'reason' => null],
            ],
        ];
    }

    public static function set(int $academyId, string $choice): void
    {
        if (!in_array($choice, ['', 'api', 'manual', 'off'], true)) {
            throw ApiException::validation(['whatsapp_preference' => 'Choose Manual, Automatic or Off.']);
        }
        $plan = Feature::forAcademy($academyId)['whatsapp_package_mode'] ?? 'off';
        if ($choice === 'api' && $plan !== 'api') {
            throw ApiException::validation(['whatsapp_preference' => 'Automatic WhatsApp is not included in your plan.']);
        }
        if ($choice === 'manual' && $plan === 'off') {
            throw ApiException::validation(['whatsapp_preference' => 'WhatsApp is not included in your plan.']);
        }
        $s = Settings::academy($academyId);
        $s['whatsapp_preference'] = $choice === 'api' ? '' : $choice;
        DB::update('academies', ['settings' => json_encode($s)], 'id = ?', [$academyId]);
        Feature::flush();
    }
}

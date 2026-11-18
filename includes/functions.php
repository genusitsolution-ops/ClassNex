<?php
declare(strict_types=1);

/** Small helpers used by every page, the API and the cron scripts. Loaded by includes/bootstrap.php. */

/** HTML-escape for output. */
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Absolute URL inside the installation (works in a sub-folder such as /ClassNex/). */
function cnx_url(string $path = ''): string
{
    return rtrim((string) Config::get('app_url', ''), '/') . '/' . ltrim($path, '/');
}

/**
 * Business names of the roles. Role keys stay as stored in the database and API for compatibility
 * ("owner" is the Class Admin / Academy Admin of a customer academy).
 */
function cnx_role_name(string $role): string
{
    return [
        'super_admin' => 'ClassNex Super Admin',
        'owner'       => 'Class Admin',
        'teacher'     => 'Teacher',
        'parent'      => 'Parent',
        'student'     => 'Student',
    ][$role] ?? ucwords(str_replace('_', ' ', $role));
}

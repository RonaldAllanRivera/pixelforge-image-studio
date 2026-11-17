<?php

namespace App\Support;

/**
 * An email address shown on the admin report: enough to tell accounts apart, not enough
 * to contact anyone. The demo admin login is public, so anyone could open that page.
 */
final class MaskedEmail
{
    /**
     * "allan@gmail.com" becomes "al•••@gmail.com". Counts characters, not bytes.
     */
    public static function mask(string $email): string
    {
        $at = mb_strrpos($email, '@');

        if ($at === false) {
            return mb_substr($email, 0, 2).'•••';
        }

        return mb_substr($email, 0, min(2, $at)).'•••@'.mb_substr($email, $at + 1);
    }
}

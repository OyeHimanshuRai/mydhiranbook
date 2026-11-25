<?php

namespace App\Support;

/**
 * What a refused user is told, on the web and the app: that it is not
 * available, never why (which permission, who decides). The shop owes its
 * staff no reason (owner's rule, 2026-10-30).
 */
final class NotAvailable
{
    public const MESSAGE = "This isn't available on your account.";
}

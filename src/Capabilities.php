<?php

declare(strict_types=1);

namespace Orchestra\Email;

use Orchestra\Core\Authorization\CapabilityRegistry;

/**
 * The capabilities of orchestra-email. Who may email a group is decided by the plugin that
 * registers it ({@see RecipientGroup::$capability}).
 */
final class Capabilities
{
    /**
     * See the emails of all senders in "Sent emails"; everyone else sees their own. Administrators
     * only: other senders' emails can name single members (data minimisation, see the GDPR
     * documentation of orchestra-core).
     */
    public const LOG_READ_OTHERS = 'orchestra_email_log_read_others';

    /**
     * @internal Hooked to `orchestra_core_register_capabilities`.
     */
    public static function register(CapabilityRegistry $capabilities): void
    {
        $capabilities->register(self::LOG_READ_OTHERS);
    }
}

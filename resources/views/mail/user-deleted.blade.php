<div style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
    <h2 style="margin-bottom: 4px;">Account deleted</h2>
    <p style="margin-top: 4px;">Hi {{ $user->first_name ?: $user->name }},</p>
    <p>An administrator <strong>deleted</strong> your {{ config('app.name') }} account. You can no longer sign in.</p>

    @if ($reason)
        <p><strong>Reason:</strong> {{ $reason }}</p>
    @endif

    <p>If you believe this is a mistake, reply to this email or contact support — a deleted account can be restored for a limited time.</p>

    <p style="margin-top: 24px;">Regards,<br>{{ config('app.name') }} team</p>
</div>

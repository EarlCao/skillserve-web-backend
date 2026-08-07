<div style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
    <h2 style="margin-bottom: 4px;">Account banned</h2>
    <p style="margin-top: 4px;">Hi {{ $user->first_name ?: $user->name }},</p>
    <p>Your {{ config('app.name') }} account has been <strong>banned</strong>. You can no longer sign in.</p>

    <p><strong>Reason:</strong> {{ $reason }}</p>

    @if ($bannedUntil)
        <p>
            <strong>Access restored on:</strong> {{ $bannedUntil->format('F j, Y') }} — your account will be
            unbanned automatically on that date.
        </p>
    @else
        <p>This is a <strong>permanent</strong> ban. If you believe this is a mistake, please contact support.</p>
    @endif

    <p style="margin-top: 24px;">Regards,<br>{{ config('app.name') }} team</p>
</div>

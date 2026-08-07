<div style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto; color: #1f2937;">
    <h2 style="margin-bottom: 4px;">Your account has been restored</h2>
    <p style="margin-top: 4px;">Hi {{ $user->first_name ?: $user->name }},</p>
    <p>Good news — the ban on your {{ config('app.name') }} account has been <strong>lifted</strong>.
       You can sign in again now.</p>

    @if ($note)
        <p><strong>Note:</strong> {{ $note }}</p>
    @endif

    <p style="margin-top: 24px;">Regards,<br>{{ config('app.name') }} team</p>
</div>

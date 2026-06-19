<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>CampusLoop Password Reset Request</title>
    @include('emails.styles.theme')
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">
            
            @include('emails.partials.header')

            <div class="email-body">
                <h2>Password Reset Request</h2>
                <p>Dear <strong>{{ $user->first_name }} {{ $user->last_name }}</strong>,</p>
                <p>We received a request to reset the password for your CampusLoop account. If you made this request, please click the secure button below to create a new password.</p>
                
                <div class="btn-container">
                    <a href="{{ $resetLink }}" class="btn">Reset Password</a>
                </div>

                <p><strong>Note:</strong> For security purposes, this link will expire shortly. If you did not request a password reset, you can safely ignore this email and your password will remain unchanged.</p>
                
                <p>Sincerely,<br><strong>The CampusLoop Team</strong></p>

                <div class="fallback-link">
                    <hr style="border: none; border-top: 1px solid #eeeeee; margin-bottom: 20px;">
                    If you're having trouble clicking the "Reset Password" button, copy and paste the URL below into your web browser:<br><br>
                    <a href="{{ $resetLink }}">{{ $resetLink }}</a>
                </div>
            </div>

            <div class="email-footer">
                &copy; {{ date('Y') }} CampusLoop. Administered by Holy Face.<br>
                This is an automated message, please do not reply.
            </div>

        </div>
    </div>
</body>
</html>
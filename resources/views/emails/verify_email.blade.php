<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Verify Your CampusLoop Account</title>
    @include('emails.styles.theme')
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">
            
            @include('emails.partials.header')

            <div class="email-body">
                <h2>Welcome to CampusLoop!</h2>
                <p>Dear <strong>{{ $user->first_name }} {{ $user->last_name }}</strong>,</p>
                <p>Thank you for joining CampusLoop. To complete your registration and gain full access to your account, we need to verify your email address. Please click the button below to activate your account.</p>
                
                <div class="btn-container">
                    <a href="{{ $verifyLink }}" class="btn">Verify Email Address</a>
                </div>

                <p>If you did not sign up for a CampusLoop account, please disregard this message. No further action is required.</p>
                
                <p>Sincerely,<br><strong>The CampusLoop Team</strong></p>

                <div class="fallback-link">
                    <hr style="border: none; border-top: 1px solid #eeeeee; margin-bottom: 20px;">
                    If you're having trouble clicking the "Verify Email Address" button, copy and paste the URL below into your web browser:<br><br>
                    <a href="{{ $verifyLink }}">{{ $verifyLink }}</a>
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
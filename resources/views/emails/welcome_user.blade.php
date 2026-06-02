<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Welcome to CampusLoop</title>
    @include('emails.styles.theme')
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">
            
            <div class="email-header">
                <img src="{{ $message->embed(public_path('images/logo.png')) }}" alt="CampusLoop Logo" style="max-height: 50px; display: block; margin: 0 auto;">
                <div style="margin-top: 15px;">
                    CAMPUSLOOP
                </div>
            </div>

            <div class="email-body">
                <h2>Welcome to CampusLoop!</h2>
                <p>Dear <strong>{{ $user->first_name }} {{ $user->last_name }}</strong>,</p>
                <p>An administrator has successfully created an official CampusLoop account for you. Here are your account credentials:</p>
                
                <div style="background-color: #f9f9f9; padding: 20px; border-radius: 8px; border: 1px solid #eeeeee; margin: 25px 0;">
                    <p style="margin: 0 0 10px 0; color: #333;"><strong>Role:</strong> <span style="text-transform: uppercase;">{{ $user->role }}</span></p>
                    <p style="margin: 0 0 10px 0; color: #333;"><strong>Email:</strong> {{ $user->email }}</p>
                    <p style="margin: 0; color: #333;"><strong>Password:</strong> <span style="font-family: monospace; background: #e0e0e0; padding: 3px 6px; border-radius: 3px;">{{ $rawPassword }}</span></p>
                </div>

                @if(is_null($user->email_verified_at))
                    <p>To fully activate your account, please verify your email address by clicking the button below:</p>
                    <div class="btn-container">
                        <a href="{{ $verifyLink }}" class="btn">Verify Email Address</a>
                    </div>
                @else
                    <p>Your account has been pre-activated by the admin. You can log in immediately by clicking the button below:</p>
                    <div class="btn-container">
                        <a href="{{ $loginLink }}" class="btn">Login to Dashboard</a>
                    </div>
                @endif
                
                <p style="margin-top: 30px;">Sincerely,<br><strong>The CampusLoop Team</strong></p>
            </div>

            <div class="email-footer">
                &copy; {{ date('Y') }} CampusLoop. Administered by Holy Face.<br>
                This is an automated message, please do not reply.
            </div>

        </div>
    </div>
</body>
</html>
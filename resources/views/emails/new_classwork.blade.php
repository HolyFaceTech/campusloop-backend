<!DOCTYPE html>
<html>
<head>
    <title>New Classwork Posted</title>
    @include('emails.styles.theme')
</head>
<body>
    <div class="email-wrapper">
        <div class="email-container">

            @include('emails.partials.header')

            <div class="email-body">
                <h2>Hello {{ $studentName }},</h2>
                
                <p>Teacher <strong>{{ $teacherName }}</strong> has posted a new <strong>{{ $classworkType }}</strong> in your class: <strong>{{ $subjectName }}</strong>.</p>
                
                <div style="background-color: #F9F9F9; padding: 15px; border-left: 4px solid #626F47; margin: 20px 0; border-radius: 4px;">
                    <p style="margin: 0; font-size: 16px;"><strong>Title:</strong> {{ $classworkTitle }}</p>
                    <p style="margin: 10px 0 0 0; color: #d9534f; font-weight: 600;">
                        Due Date: {{ $deadline ? \Carbon\Carbon::parse($deadline)->format('F d, Y - h:i A') : 'No Due Date' }}
                    </p>
                </div>

                <p>Click the button below to view the details and submit your work.</p>

                <div class="btn-container">
                    <a href="{{ $classworkLink }}" class="btn">View {{ $classworkType }}</a>
                </div>

                <div class="fallback-link">
                    If the button doesn't work, copy and paste this link into your browser:<br>
                    <a href="{{ $classworkLink }}">{{ $classworkLink }}</a>
                </div>
            </div>

            <div class="email-footer">
                &copy; {{ date('Y') }} CampusLoop. All rights reserved.<br>
                This is an automated notification. Please do not reply to this email.
            </div>
        </div>
    </div>
</body>
</html>
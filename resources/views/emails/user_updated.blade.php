<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Account Information Updated</title>
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
                <h2>Account Update Notice</h2>
                <p>Dear <strong>{{ $user->first_name }}</strong>,</p>
                <p>This is to inform you that an administrator has updated your CampusLoop account information. Please review the changes below:</p>
                
                <div style="background-color: #f9f9f9; padding: 20px; border-radius: 8px; border: 1px solid #eeeeee; margin: 25px 0; overflow-x: auto;">
                    
                    @if(count($changedFields) > 0)
                        <table style="width: 100%; font-size: 14px; color: #333; line-height: 1.8; border-collapse: collapse; table-layout: fixed;">
                            <thead>
                                <tr style="border-bottom: 2px solid #ddd; text-align: left;">
                                    <th style="padding-bottom: 8px;">Information</th>
                                    <th style="padding-bottom: 8px;">Previous</th>
                                    <th style="padding-bottom: 8px;">Updated To</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($changedFields as $label => $values)
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 8px 5px 8px 0; color: #555; word-wrap: break-word; word-break: break-word;"><strong>{{ $label }}</strong></td>
                                    
                                    <td style="padding: 8px 5px 8px 0; color: #999; word-wrap: break-word; word-break: break-word;">
                                        @if($label === 'Password')
                                            ********
                                        @else
                                            <strike>{{ $values['old'] ?: 'N/A' }}</strike>
                                        @endif
                                    </td>

                                    <td style="padding: 8px 0; color: #626F47; font-weight: 600; word-wrap: break-word; word-break: break-word;">
                                        @if($label === 'Password')
                                            <span style="font-family: monospace; background: #ffcdd2; padding: 3px 8px; border-radius: 4px; color: #b71c1c; display: inline-block; word-break: break-all;">
                                                {{ $values['new'] }}
                                            </span>
                                        @else
                                            <span style="text-transform: uppercase;">{{ $values['new'] ?: 'N/A' }}</span>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p style="margin: 0; color: #555; text-align: center;">No personal details were modified.</p>
                    @endif

                </div>

                <p style="margin-top: 30px;">If you believe this update was a mistake, please contact your school administrator immediately.</p>
                
                <p>Sincerely,<br><strong>The CampusLoop Team</strong></p>
            </div>

            <div class="email-footer">
                &copy; {{ date('Y') }} CampusLoop. Administered by Holy Face.<br>
                This is an automated message, please do not reply.
            </div>

        </div>
    </div>
</body>
</html>
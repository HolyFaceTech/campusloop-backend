<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>System Analytics Report</title>
    @include('print.style.report')
</head>
<body>

    <div class="header">
        <img src="{{ public_path('images/logo.png') }}" alt="School Logo" style="width: 100px; height: auto; margin-bottom: 10px;">
        
        <h1>HOLY FACE OF JESUS LYCEUM OF SAN JOSE INC</h1>
        <p>BLOCK 5 LOT 28-34 VALENTINO VILLAGE, SAN JOSE, RODRIGUEZ, RIZAL</p>
        <p>CONTACT: 09164369291</p>
    </div>

    <div class="date-section">
        <strong>Date Generated:</strong> {{ \Carbon\Carbon::now()->format('F d, Y h:i A') }}<br>
        <strong>Active Configuration:</strong> SY {{ $settings['school_year'] ?? 'Not Set' }} | {{ isset($settings['semester']) ? $settings['semester'].' Semester' : 'Not Set' }}
    </div>

    <div class="report-title">
        Comprehensive System Analytics Report
    </div>

    <p>To the School Administration,</p>
    <p>This document presents the formal executive summary of the Learning Management System. The data enclosed reflects the real-time statistics and engagement metrics across all active modules within the system.</p>

    <div class="section-title">1. User Demographics</div>
    <table>
        <thead>
            <tr>
                <th>User Role</th>
                <th class="text-center">Active Accounts</th>
                <th class="text-center">Inactive Accounts</th>
                <th class="text-center">Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Students</td>
                <td class="text-center">{{ $users['students_active'] }}</td>
                <td class="text-center">{{ $users['students_inactive'] }}</td>
                <td class="text-center"><strong>{{ $users['students_active'] + $users['students_inactive'] }}</strong></td>
            </tr>
            <tr>
                <td>Teachers</td>
                <td class="text-center">{{ $users['teachers_active'] }}</td>
                <td class="text-center">{{ $users['teachers_inactive'] }}</td>
                <td class="text-center"><strong>{{ $users['teachers_active'] + $users['teachers_inactive'] }}</strong></td>
            </tr>
            <tr>
                <td>Administrators</td>
                <td class="text-center">{{ $users['admins_active'] }}</td>
                <td class="text-center">{{ $users['admins_inactive'] }}</td>
                <td class="text-center"><strong>{{ $users['admins_active'] + $users['admins_inactive'] }}</strong></td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">2. Academic Overview</div>
    <table>
        <thead>
            <tr>
                <th>Strand Name</th>
                <th class="text-center">Total Enrolled Students</th>
            </tr>
        </thead>
        <tbody>
            @forelse($strands as $strand)
            <tr>
                <td>{{ $strand->name }} ({{ $strand->description }})</td>
                <td class="text-center">{{ $strand->users_count }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="2" class="text-center">No strands available.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
    
    <ul>
        <li><strong>Total Active Subjects:</strong> {{ $academics['subjects'] }}</li>
        <li><strong>Total Active Classrooms:</strong> {{ $academics['classrooms'] }}</li>
        <li><strong>Total Advisory Classes:</strong> {{ $academics['advisories'] }}</li>
    </ul>

    <div class="section-title">3. Content & Engagement Metrics</div>
    <table>
        <thead>
            <tr>
                <th>Content Type</th>
                <th class="text-center">Total Count</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Classworks Posted (Quizzes, Assignments, etc.)</td>
                <td class="text-center">{{ $engagement['classworks'] }}</td>
            </tr>
            <tr>
                <td>Forms / Questionnaires Created</td>
                <td class="text-center">{{ $engagement['forms'] }}</td>
            </tr>
            <tr>
                <td>Global Announcements</td>
                <td class="text-center">{{ $engagement['announcements'] }}</td>
            </tr>
            <tr>
                <td>Files Uploaded (PDFs, Docs, Images)</td>
                <td class="text-center">{{ $engagement['files'] }}</td>
            </tr>
            <tr>
                <td>E-Library Resources (Approved)</td>
                <td class="text-center">{{ $engagement['elibrary'] }}</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">4. Active Teachers Activity & Contributions</div>
    <table>
        <thead>
            <tr>
                <th>Teacher Name</th>
                <th class="text-center">Active Classrooms</th>
                <th class="text-center">Forms Created</th>
                <th class="text-center">Files Uploaded</th>
            </tr>
        </thead>
        <tbody>
            @forelse($teachers as $teacher)
            <tr>
                <td>{{ $teacher['name'] }}</td>
                <td class="text-center">{{ $teacher['classrooms_count'] }}</td>
                <td class="text-center">{{ $teacher['forms_count'] }}</td>
                <td class="text-center">{{ $teacher['files_count'] }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="4" class="text-center">No active teachers found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="signature-block">
        <p>Prepared & Generated By:</p>
        <br><br>
        <div class="signature-line"></div>
        <strong>{{ strtoupper($generator_name) }}</strong><br>
        System Administrator 
    </div>

    <div class="footer">
        &copy; {{ date('Y') }} CampusLoop. All rights reserved. This is an auto-generated system document.
    </div>

</body>
</html>
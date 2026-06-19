<div class="email-header">
    @php
        $logoPath = public_path('images/logo.png');
    @endphp
    @if (isset($message) && is_file($logoPath))
        <img src="{{ $message->embed($logoPath) }}" alt="CampusLoop Logo" style="max-height: 50px; display: block; margin: 0 auto;">
        <div style="margin-top: 15px;">
            CAMPUSLOOP
        </div>
    @else
        CAMPUSLOOP
    @endif
</div>

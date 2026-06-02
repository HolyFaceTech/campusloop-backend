<!DOCTYPE html>
<html>
<head>
    <title>{{ $form->name }} - Teacher Copy</title>
    @include('print.style.printform')
</head>
<body>
    @php
        function toRoman($num) {
            $n = intval($num);
            $res = '';
            $romanNumber_Array = [
                100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL',
                10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'
            ];
            foreach ($romanNumber_Array as $roman => $number){
                $matches = intval($n / $roman);
                $res .= str_repeat($number, $matches);
                $n = $n % $roman;
            }
            return $res;
        }
        $totalPoints = collect($form->questions)->sum('points');
    @endphp

    <div class="letterhead">
        <img src="/images/logo.png" class="letterhead-logo" alt="Logo" onerror="this.style.display='none'" />
        <div class="letterhead-text">
            <h1 class="school-name">HOLY FACE OF JESUS LYCEUM OF SAN JOSE INC.</h1>
            <p class="school-address">BLOCK 5 LOT 28-34 VALENTINO VILLAGE, SAN JOSE, RODRIGUEZ, RIZAL</p>
            <p class="school-contact">CONTACT: 09164369291</p>
        </div>
    </div>

    <div class="form-title">OFFICIAL QUESTIONNAIRE</div>
    <div class="form-instruction" style="font-weight: bold; font-style: normal;">{{ $form->name }} <br> <span style="font-weight: normal; font-style: italic; white-space: pre-line;">{{ $form->instruction }}</span></div>

    <div class="info-container">
        <div class="info-col">
            <div class="info-row">
                <span class="info-label">Prepared By:</span>
                <span class="info-value">{{ $form->creator->first_name ?? '' }} {{ $form->creator->last_name ?? '' }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Date Created:</span>
                <span class="info-value">{{ \Carbon\Carbon::parse($form->created_at)->format('F d, Y') }}</span>
            </div>
        </div>
        <div class="info-col">
            <div class="info-row">
                <span class="info-label">Time Limit:</span>
                <span class="info-value text-center">{{ $form->timer > 0 ? $form->timer . ' Minutes' : 'None' }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Total Points:</span>
                <span class="info-value text-center" style="font-weight: bold;">{{ $totalPoints }}</span>
            </div>
        </div>
    </div>

    <div class="content-wrapper">
        @php
            // I-GRUPO MUNA ANG MGA QUESTIONS PARA HINDI MAGKAHALO-HALO
            $groupedQuestions = collect($form->questions)->groupBy('section');
            $sectionIndex = 1;
        @endphp

        @foreach($groupedQuestions as $sectionName => $questions)
            @if($sectionName)
                <div class="section-block">
                    <span class="section-title">{{ toRoman($sectionIndex++) }}. {{ $sectionName }}:</span>
                    @php
                        // Hanapin ang instruction para sa section na ito
                        $secInstruction = $questions->firstWhere('instruction', '!=', null);
                    @endphp
                    @if($secInstruction && $secInstruction->instruction)
                        <span class="section-instruction"> {{ $secInstruction->instruction }}</span>
                    @endif
                </div>
            @endif

            @php $questionNum = 1; /* RESET BILANG PER SECTION */ @endphp

            @foreach($questions as $q)
                <div class="q-container">
                    <div class="q-header">
                        <div class="q-text">{{ $questionNum++ }}. {{ $q->text }}</div>
                        <div class="q-points">{{ $q->points }} pt/s</div>
                    </div>

                    @if($q->type === 'multiple_choice' && $q->choices)
                        <div class="choices-grid">
                            @foreach($q->choices as $choice)
                                @php $isCorrect = ($q->correct_answer === $choice); @endphp
                                <div class="choice-col {!! $isCorrect ? 'text-success' : '' !!}">
                                    {!! $isCorrect ? '&#9679;' : '&#9711;' !!} {{ $choice }}
                                </div>
                            @endforeach
                        </div>
                    @elseif($q->type === 'short_answer')
                        <div class="short-answer text-success">Correct Answer: <u>{{ $q->correct_answer }}</u></div>
                    @endif
                </div>
            @endforeach
        @endforeach
    </div>

    <div class="print-meta">
        <strong>Printed by:</strong> {{ $admin->first_name ?? 'Administrator' }} {{ $admin->last_name ?? '' }} on {{ \Carbon\Carbon::now()->format('F d, Y \a\t h:i A') }}
    </div>

    <script>
        window.onload = function() { 
            setTimeout(() => { 
                window.print(); 
            }, 800); 
        }
    </script>
</body>
</html>
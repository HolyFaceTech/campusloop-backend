<!DOCTYPE html>
<html>
<head>
    <title>{{ $submission->student->last_name ?? 'Student' }} - {{ $form->name }}</title>
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
        $answersMap = collect($submission->answers)->keyBy('question_id');
    @endphp

    <div class="letterhead">
        <img src="/images/logo.png" class="letterhead-logo" alt="Logo" onerror="this.style.display='none'" />
        <div class="letterhead-text">
            <h1 class="school-name">HOLY FACE OF JESUS LYCEUM OF SAN JOSE INC.</h1>
            <p class="school-address">BLOCK 5 LOT 28-34 VALENTINO VILLAGE, SAN JOSE, RODRIGUEZ, RIZAL</p>
            <p class="school-contact">CONTACT: 09164369291</p>
        </div>
    </div>

    <div class="form-title">STUDENT EXAMINATION SUBMISSION</div>
    <div class="form-instruction" style="font-weight: bold; font-style: normal;">{{ $form->name }} <br> <span style="font-weight: normal; font-style: italic; white-space: pre-line;">{{ $form->instruction }}</span></div>

    <div class="info-container" style="align-items: center;">
        <div class="info-col" style="width: 70%;">
            <div class="info-row">
                <span class="info-label">Student Name:</span>
                <span class="info-value">{{ $submission->student->first_name ?? '' }} {{ $submission->student->last_name ?? '' }}</span>
            </div>
            <div class="info-row">
                <span class="info-label" style="width: 50px;">LRN:</span>
                <span class="info-value" style="width: 150px; flex-grow: 0;">{{ $submission->student->lrn ?? 'N/A' }}</span>
                <span class="info-label" style="margin-left: 15px;">Strand:</span>
                <span class="info-value">{{ $submission->student->strand->name ?? 'N/A' }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Date Submitted:</span>
                <span class="info-value">{{ \Carbon\Carbon::parse($submission->submitted_at)->format('F d, Y \a\t h:i A') }}</span>
            </div>
        </div>
        <div class="info-col" style="width: 25%; display: flex; justify-content: flex-end;">
            <div class="score-box">
                <div style="font-size: 10pt; font-weight: normal; text-transform: uppercase; color: #555;">Final Score</div>
                <span style="font-size: 24pt; color: #2c3e50;">{{ $submission->score }}</span> / {{ $totalPoints }}
            </div>
        </div>
    </div>

    <div class="content-wrapper">
        @php
            // I-GRUPO MUNA ANG MGA QUESTIONS
            $groupedQuestions = collect($form->questions)->groupBy('section');
            $sectionIndex = 1;
        @endphp

        @foreach($groupedQuestions as $sectionName => $questions)
            @if($sectionName)
                <div class="section-block">
                    <span class="section-title">{{ toRoman($sectionIndex++) }}. {{ $sectionName }}:</span>
                    @php
                        $secInstruction = $questions->firstWhere('instruction', '!=', null);
                    @endphp
                    @if($secInstruction && $secInstruction->instruction)
                        <span class="section-instruction"> {{ $secInstruction->instruction }}</span>
                    @endif
                </div>
            @endif

            @php $questionNum = 1; /* RESET BILANG PER SECTION */ @endphp

            @foreach($questions as $q)
                @php 
                    $ans = $answersMap->get($q->id);
                    $isCorrect = $ans ? $ans->is_correct : false;
                    $earned = $ans ? $ans->points_earned : 0;
                    $studentAnsText = $ans ? $ans->student_answer : '';
                @endphp

                <div class="q-container">
                    <div class="q-header">
                        <div class="q-text">{{ $questionNum++ }}. {{ $q->text }}</div>
                        <div class="q-points">{{ $earned }} / {{ $q->points }}</div>
                    </div>

                    @if($q->type === 'multiple_choice' && $q->choices)
                        <div class="choices-grid">
                            @foreach($q->choices as $choice)
                                @php 
                                    $isSelected = ($studentAnsText === $choice);
                                    $mark = '&#9711;';
                                    $class = '';
                                    if ($isSelected) {
                                        $mark = $isCorrect ? '&#10004;' : '&#10008;';
                                        $class = $isCorrect ? 'text-success' : 'text-danger';
                                    }
                                @endphp
                                <div class="choice-col {!! $class !!}">{!! $mark !!} {{ $choice }}</div>
                            @endforeach
                        </div>
                        @if(!$isCorrect && $studentAnsText)
                            <div class="correction-text">Correct Answer: {{ $q->correct_answer }}</div>
                        @endif
                    @elseif($q->type === 'short_answer')
                        <div class="short-answer {!! $isCorrect ? 'text-success' : 'text-danger' !!}">
                            Answer: <u>{{ $studentAnsText ?: 'No Answer' }}</u> {!! $isCorrect ? '&#10004;' : '&#10008;' !!}
                        </div>
                        @if(!$isCorrect)
                            <div class="correction-text">Correct Answer: {{ $q->correct_answer }}</div>
                        @endif
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
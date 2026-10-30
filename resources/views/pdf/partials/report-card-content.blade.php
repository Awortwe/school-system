@php
    $school = $report['school'];
    $student = $report['student'];
    $summary = $report['summary'];
    $attendance = $report['attendance'];
    $conduct = $report['conduct'];
    $settings = $report['settings'];
    $logo = $school['logo_url'] ?? null;
    $logoPath = null;
    if ($logo && str_starts_with($logo, '/')) {
        $candidate = public_path(ltrim($logo, '/'));
        $logoPath = file_exists($candidate) ? $candidate : null;
    }
    $logoPath = $school['logo_data_uri'] ?? $logoPath;
    $photoPath = $student['photo_path'] ?? null;
    $componentCount = count($report['subjects'][0]['components'] ?? []);
    $componentWidth = $componentCount > 0 ? round(42 / $componentCount, 2) : 0;
    $subjectWidth = 23;
@endphp
<div class="page">
    @if(!empty($report['is_draft']))
        <div class="watermark">DRAFT</div>
    @elseif(!empty($copy))
        <div class="watermark">COPY</div>
    @endif

    <div class="content">
        <table class="school-header">
            <tr>
                <td style="width: 64px;">
                    @if($logoPath)
                        <img src="{{ $logoPath }}" class="logo" alt="Logo">
                    @endif
                </td>
                <td>
                    <div class="school-name">{{ $school['name'] }}</div>
                    <div class="school-motto">{{ $school['motto'] }}</div>
                    <div class="school-contact">{{ $school['address'] }} &nbsp;|&nbsp; {{ $school['phone'] }} &nbsp;|&nbsp; {{ $school['email'] }}</div>
                </td>
                <td class="report-title" style="width: 120px;">
                    <div class="main">{{ $report['template']['title'] ?? 'TERMINAL REPORT' }}</div>
                    <div class="term">{{ $report['term']['name'] }} &middot; {{ $report['term']['academic_year'] }}</div>
                    <div class="term">{{ $student['level'] }} &middot; {{ $student['class'] }}</div>
                </td>
            </tr>
        </table>
        <div class="gold-rule"></div>

        <table class="student-table">
            <tr>
                <td style="width: 76px;">
                    @if($photoPath)
                        <img src="{{ $photoPath }}" class="photo" alt="Student photo">
                    @else
                        <div class="photo-box">PHOTO</div>
                    @endif
                </td>
                <td>
                    <table class="info-grid">
                        <tr>
                            <td style="width: 50%;"><div class="label">Student Name</div><div class="value">{{ $student['name'] }}</div></td>
                            <td><div class="label">Admission No.</div><div class="value">{{ $student['admission_no'] }}</div></td>
                        </tr>
                        <tr>
                            <td><div class="label">Class</div><div class="value">{{ $student['class'] }}</div></td>
                            <td><div class="label">Gender / Date of Birth</div><div class="value">{{ $student['gender'] ?? '-' }} &middot; {{ $student['date_of_birth'] ? \Carbon\Carbon::parse($student['date_of_birth'])->format('d M Y') : '-' }}</div></td>
                        </tr>
                        <tr>
                            <td><div class="label">Term</div><div class="value">{{ $report['term']['name'] }}</div></td>
                            <td><div class="label">Academic Year</div><div class="value">{{ $report['term']['academic_year'] }}</div></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <table class="summary-table">
            <tr>
                <td><div class="num">{{ $summary['total'] !== null ? number_format($summary['total'], 1) : '-' }}</div><div class="cap">Total Score</div></td>
                <td><div class="num">{{ $summary['average'] !== null ? number_format($summary['average'], 1) : '-' }}</div><div class="cap">Average</div></td>
                @if($settings['show_position'])
                    <td><div class="num">{{ $summary['position'] ?? '-' }}</div><div class="cap">Position (of {{ $summary['class_size'] ?? '-' }})</div></td>
                @endif
                @if($settings['show_attendance'])
                    <td><div class="num">{{ $attendance['percentage'] !== null ? number_format($attendance['percentage'], 1).'%' : '-' }}</div><div class="cap">Attendance</div></td>
                @endif
                <td><div class="num">{{ $summary['subjects_counted'] }}</div><div class="cap">Subjects</div></td>
            </tr>
        </table>

        @if(!empty($report['observations']))
        <h3>Learning and developmental observations</h3>
        <table class="subjects"><thead><tr><th>Area</th><th>Skill / activity</th><th>Progress</th><th>Teacher observation</th></tr></thead><tbody>
        @foreach($report['observations'] as $observation)
        <tr><td>{{ $observation['area'] }}</td><td>{{ $observation['skill'] }}</td><td>{{ ucfirst($observation['progress']) }}</td><td>{{ $observation['notes'] }}</td></tr>
        @endforeach
        </tbody></table>
        @endif
        <h3>Academic Performance</h3>
        <table class="subjects">
            <thead>
                <tr>
                    <th style="width: {{ $subjectWidth }}%; text-align: left;">Subject</th>
                    @foreach(($report['subjects'][0]['components'] ?? []) as $component)
                        <th style="width: {{ $componentWidth }}%;">{{ $component['code'] }}<br><span style="font-weight: normal;">({{ $component['max_score'] }})</span></th>
                    @endforeach
                    <th style="width: 8%;">Total</th>
                    <th style="width: 7%;">Grade</th>
                    <th style="width: 20%;">Remark</th>
                </tr>
            </thead>
            <tbody>
                @forelse($report['subjects'] as $subject)
                    <tr>
                        <td class="subject">{{ $subject['subject'] }}</td>
                        @foreach($subject['components'] as $component)
                            <td class="{{ $component['missing'] ? 'missing' : '' }}">
                                @if($component['is_exempt'])
                                    EX
                                @elseif($component['score'] !== null)
                                    {{ number_format((float) $component['score'], 1) }}
                                @else
                                    &ndash;
                                @endif
                            </td>
                        @endforeach
                        <td class="total">{{ $subject['total_score'] !== null ? number_format($subject['total_score'], 1) : '-' }}</td>
                        <td class="total">{{ $subject['grade'] ?? '-' }}</td>
                        <td class="remark">{{ $subject['remark'] ?? ($subject['is_complete'] ? '' : 'Incomplete') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ 3 + $componentCount }}" style="text-align:center; padding: 12px; color:#64748b;">No subjects have been set up for this class and term.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if($settings['show_conduct'])
            <table class="two-col">
                <tr>
                    <td style="padding-right: 4px;">
                        <div class="box">
                            <div class="box-title">Conduct &amp; Character</div>
                            <div class="line"><strong>Conduct:</strong> {{ $conduct['conduct'] ? ucfirst(str_replace('_', ' ', $conduct['conduct'])) : '-' }}</div>
                            <div class="line"><strong>Attitude:</strong> {{ $conduct['attitude'] ? ucfirst(str_replace('_', ' ', $conduct['attitude'])) : '-' }}</div>
                            <div class="line"><strong>Interest:</strong> {{ $conduct['interest'] ? ucfirst(str_replace('_', ' ', $conduct['interest'])) : '-' }}</div>
                            @if($conduct['height'] || $conduct['weight'])
                                <div class="line"><strong>Height / Weight:</strong> {{ $conduct['height'] ?: '-' }} / {{ $conduct['weight'] ?: '-' }}</div>
                            @endif
                        </div>
                    </td>
                    <td style="padding-left: 4px;">
                        @if($settings['show_attendance'])
                            <div class="box">
                                <div class="box-title">Attendance</div>
                                <div class="line">Present: <strong>{{ $attendance['present'] }}</strong> &nbsp; Late: <strong>{{ $attendance['late'] }}</strong></div>
                                <div class="line">Absent: <strong>{{ $attendance['absent'] }}</strong> &nbsp; Excused: <strong>{{ $attendance['excused'] }}</strong></div>
                                <div class="line">Attendance sessions recorded: <strong>{{ $attendance['total'] }}</strong></div>
                            </div>
                        @endif
                    </td>
                </tr>
            </table>
        @endif

        <table class="two-col">
            <tr>
                <td style="padding-right: 4px;">
                    <div class="box">
                        <div class="box-title">Class Teacher's Comment</div>
                        <div class="comment">{{ $conduct['class_teacher_comment'] ?: 'No comment recorded.' }}</div>
                    </div>
                </td>
                <td style="padding-left: 4px;">
                    <div class="box">
                        <div class="box-title">{{ $settings['headteacher_title'] }}'s Comment</div>
                        <div class="comment">{{ $conduct['headteacher_comment'] ?: 'No comment recorded.' }}</div>
                    </div>
                </td>
            </tr>
        </table>

        <table class="two-col">
            <tr>
                <td style="padding-right: 4px;"><div class="box"><div class="box-title">Closing Date</div><div class="value">{{ $report['term']['closing_date'] ? \Carbon\Carbon::parse($report['term']['closing_date'])->format('d M Y') : '-' }}</div></div></td>
                <td style="padding-left: 4px;"><div class="box"><div class="box-title">Next Term Begins</div><div class="value">{{ $report['term']['reopening_date'] ? \Carbon\Carbon::parse($report['term']['reopening_date'])->format('d M Y') : '-' }}</div></div></td>
            </tr>
        </table>

        @if($settings['show_signatures'])
            <table class="signatures">
                <tr>
                    @foreach($report['signatures'] as $signature)
                        <td>
                            @if(!empty($signature['image_path']))
                                @php
                                    $sig = str_replace('\\', '/', $signature['image_path']);
                                    $sigSrc = str_starts_with($sig, 'data:') ? $sig : (str_starts_with($sig, '/') ? 'file://'.$sig : 'file:///'.$sig);
                                @endphp
                                <img src="{{ $sigSrc }}" class="sig-image" alt="Signature">
                            @else
                                <div style="height: 24px;"></div>
                            @endif
                            <div class="sig-line">
                                <div class="sig-name">{{ $signature['name'] }}</div>
                                {{ $signature['title'] }}
                            </div>
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif

        <div class="footer">
            {{ $report['template']['footer'] ?? '' }}
            This report card was generated by The Sanctuary Schools management system on {{ \Carbon\Carbon::parse($report['published_at'])->format('d M Y, H:i') }}.
            @if(!empty($report['is_draft']))
                This is a draft and is not valid until results are approved and published.
            @endif
        </div>
    </div>
</div>


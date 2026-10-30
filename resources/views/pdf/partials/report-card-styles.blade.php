<style>
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5px; color: #172033; margin: 0; }
        @page { margin: 12mm; }
        .page { position: relative; padding: 0; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        td, th { overflow-wrap: break-word; }
        .watermark { position: fixed; top: 105mm; left: 35mm; font-size: 60px; color: rgba(0, 6, 156, 0.07); font-weight: bold; transform: rotate(-28deg); z-index: 0; }
        .content { position: relative; z-index: 2; }
        .school-header { width: 100%; border-collapse: collapse; border-bottom: 2.5px solid {{ $school['primary_color'] }}; padding-bottom: 4px; }
        .school-header td { vertical-align: middle; }
        .logo { width: 58px; max-height: 64px; }
        .school-name { font-size: 18px; font-weight: bold; color: {{ $school['primary_color'] }}; letter-spacing: 0.4px; }
        .school-motto { font-size: 8px; color: #535b6d; font-style: italic; }
        .school-contact { font-size: 7px; color: #4b5563; margin-top: 2px; }
        .report-title { text-align: right; }
        .report-title .main { font-size: 13px; font-weight: bold; color: {{ $school['primary_color'] }}; }
        .report-title .term { font-size: 8px; color: #374151; margin-top: 3px; }
        .gold-rule { height: 3px; background: {{ $school['gold_color'] }}; margin: 3px 0 7px; }
        .student-table { width: 100%; border-collapse: collapse; margin-bottom: 7px; }
        .student-table td { vertical-align: top; padding: 1px 0; }
        .photo-box { width: 68px; height: 76px; border: 1px solid #cbd5e1; background: #f8fafc; text-align: center; color: #94a3b8; font-size: 7px; padding-top: 30px; }
        .photo { width: 68px; height: 76px; object-fit: cover; border: 1px solid #cbd5e1; }
        .info-grid { width: 100%; border-collapse: collapse; }
        .info-grid td { padding: 2px 4px; font-size: 8px; }
        .label { color: #64748b; text-transform: uppercase; font-size: 6.5px; letter-spacing: 0.3px; }
        .value { font-weight: bold; color: #0f172a; }
        .summary-table { width: 100%; border-collapse: collapse; margin: 4px 0 8px; }
        .summary-table td { border: 1px solid #dbe2ea; background: #f8fafc; text-align: center; padding: 5px 3px; width: 20%; }
        .summary-table .num { font-size: 13px; font-weight: bold; color: {{ $school['primary_color'] }}; }
        .summary-table .cap { font-size: 6.5px; color: #64748b; text-transform: uppercase; }
        h3 { font-size: 8.5px; color: {{ $school['primary_color'] }}; margin: 7px 0 3px; text-transform: uppercase; letter-spacing: 0.4px; }
        .subjects { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .subjects th { background: {{ $school['primary_color'] }}; color: #fff; font-size: 6.5px; padding: 4px 2px; border: 1px solid {{ $school['primary_color'] }}; }
        .subjects td { border: 1px solid #dbe2ea; padding: 3px 3px; font-size: 7.5px; text-align: center; vertical-align: middle; }
        .subjects td.subject { text-align: left; font-weight: bold; }
        .subjects tbody tr:nth-child(even) td { background: #f8fafc; }
        .subjects .total { font-weight: bold; }
        .subjects .remark { text-align: left; font-size: 7px; }
        .missing { color: #b91c1c; }
        .two-col { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .two-col > tbody > tr > td { vertical-align: top; width: 50%; }
        .box { border: 1px solid #dbe2ea; background: #fff; padding: 5px 6px; min-height: 42px; }
        .box-title { font-size: 7px; font-weight: bold; color: {{ $school['primary_color'] }}; text-transform: uppercase; margin-bottom: 3px; }
        .line { margin: 2px 0; }
        .comment { border-left: 3px solid {{ $school['gold_color'] }}; padding-left: 5px; line-height: 1.45; min-height: 20px; }
        .signatures { width: 100%; border-collapse: collapse; margin-top: 12px; }
        .signatures td { width: 33.33%; text-align: center; vertical-align: bottom; padding: 0 6px; }
        .sig-line { border-top: 1px solid #334155; margin-top: 4px; padding-top: 2px; font-size: 6.5px; color: #475569; }
        .sig-name { font-size: 7px; font-weight: bold; color: #1e293b; }
        .sig-image { height: 24px; max-width: 95px; }
        .footer { margin-top: 8px; border-top: 1px solid #dbe2ea; padding-top: 3px; font-size: 6px; color: #94a3b8; text-align: center; }
    </style>

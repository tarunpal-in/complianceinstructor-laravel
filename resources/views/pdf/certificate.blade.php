<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Certificate {{ $serial }}</title>
    <style>
        @page { margin: 0; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, 'Times New Roman', serif;
            color: #101a2c;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .sheet {
            position: relative;
            width: 297mm;
            height: 209mm;
            padding: 16mm;
            background: #fbf6ec;
        }

        .frame {
            height: 100%;
            border: 2px solid #0c4e80;
            outline: 1px solid #f8ad1b;
            outline-offset: 5px;
            padding: 14mm 18mm;
            display: flex;
            flex-direction: column;
            text-align: center;
        }

        .issuer { font-size: 13pt; letter-spacing: 3px; text-transform: uppercase; color: #0c4e80; font-weight: bold; }
        .issuer-tagline { font-size: 10pt; color: #5a6472; margin-top: 3px; font-family: Arial, sans-serif; }

        .title { margin-top: 10mm; font-size: 30pt; letter-spacing: 6px; text-transform: uppercase; color: #054376; }
        .subtitle { margin-top: 2mm; font-size: 12pt; color: #5a6472; font-family: Arial, sans-serif; letter-spacing: 1px; }

        .awarded { margin-top: 9mm; font-size: 11pt; color: #5a6472; font-family: Arial, sans-serif; }
        .learner { margin-top: 3mm; font-size: 26pt; color: #101a2c; border-bottom: 1px solid #d9cfb8; display: inline-block; padding: 0 8mm 3mm; }

        .for { margin-top: 7mm; font-size: 11pt; color: #5a6472; font-family: Arial, sans-serif; }
        .webinar { margin-top: 2mm; font-size: 16pt; color: #0c4e80; font-weight: bold; }

        .credits {
            margin-top: 6mm; display: inline-block; align-self: center;
            background: #0c4e80; color: #fff; font-family: Arial, sans-serif;
            font-size: 12pt; font-weight: bold; padding: 3mm 8mm; border-radius: 3mm; letter-spacing: 1px;
        }

        .foot { margin-top: auto; display: flex; justify-content: space-between; align-items: flex-end; font-family: Arial, sans-serif; }
        .foot .block { font-size: 9pt; color: #5a6472; text-align: left; }
        .foot .block.right { text-align: right; }
        .foot .value { font-size: 11pt; color: #101a2c; font-weight: bold; }
        .foot .rule { width: 60mm; border-top: 1px solid #9aa3b0; margin-bottom: 2mm; }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="frame">
            <div class="issuer">{{ $businessName }}</div>
            @if ($businessTagline)
                <div class="issuer-tagline">{{ $businessTagline }}</div>
            @endif

            <div class="title">Certificate of Completion</div>
            <div class="subtitle">Continuing Education Credit</div>

            <div class="awarded">This certifies that</div>
            <div class="learner">{{ $learnerName }}</div>

            <div class="for">has successfully completed</div>
            <div class="webinar">{{ $webinarTitle }}</div>

            <div class="credits">{{ $credits }}</div>

            <div class="foot">
                <div class="block">
                    <div class="rule"></div>
                    <div>Issued {{ $issuedAt->format('j F Y') }}</div>
                    <div>Accrediting body: <span class="value">{{ $accreditationCode }}</span></div>
                </div>
                <div class="block right">
                    <div>Certificate serial</div>
                    <div class="value">{{ $serial }}</div>
                    <div>Issued by {{ $businessLegalName ?: $businessName }}</div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

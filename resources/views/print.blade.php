<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lotteries Diarias</title>
    <style type="text/css">
        * {
            margin: 0px;
            padding: 0px;
        }

        body {
            font-family: sans-serif;

        }

        .mainwrp {

            font-weight: bold;
            text-align: center;
        }

        table {
            margin: 20px auto;
            width: 90%;
        }

        .table1 {
            border: 1px solid black;
        }

        .table1 td {
            border-top: 1px solid black;
            border-bottom: 1px solid black;
        }

        .height {
            line-height: 35px;
            padding: 3px 5px;
            font-weight: bold;
        }

        .height td {
            padding: 3px 8px;

        }

        .height1 td {
            padding: 3px 8px;

            font-weight: bold;
        }

        /* Hide the print button when printing */
        @media print {
            button {
                display: none;
            }
        }


        button {
            padding: 15px;
            margin: 50px;
            color: white;
            background-color: darkblue;
            border: none;
            cursor: pointer;
            border-radius: 5px;
        }
    </style>



</head>

<body style="font-size: 50px;">
    @php
        use Carbon\Carbon;

        // Set the Haiti timezone
        $haitiTimezone = 'America/Port-au-Prince';

        // Parse the order date in the Haiti timezone
        $orderDateTime = Carbon::parse($data['lotteryData']['order_date'], $haitiTimezone);

        // Get the current date and time in Haiti
        $currentDateTime = Carbon::now($haitiTimezone);

        // Check if the order date matches today's date and time is before 10:30 PM
        $showPrintButton = $orderDateTime->isToday() && $currentDateTime->lte($orderDateTime->copy()->setTime(22, 30));
    @endphp

    @if ($showPrintButton)
        <button class="" onclick="window.print()">Print</button>
    @endif
    @if ($adminUser->company_header != null)
        <center>
            <img style="width: 50%; height: 50%;" src="{{ asset($adminUser->company_image) }}" alt="company logo">
        </center>
    @endif
    @if ($adminUser->company_header != null)
        <center style="margin-bottom: 30px;" class="">
            <strong class="company-header">{!! nl2br(e(str_replace(',', "\n", sprintf($adminUser->company_header)))) !!}</strong>

        </center>
    @endif
    @php
        // Define the translations for the headings
        $translations = [
            'en' => [
                'date' => 'Date:',
                'seller' => 'Seller:',
                'receipt_id' => 'Receipt Id:',
                'game' => 'Game',
                'balls' => 'Balls',
                'amount' => 'Amount',
                'total' => 'Total:',
                'total_amount' => 'Total Amount:',
            ],
            'es' => [
                'date' => 'Fecha:',
                'seller' => 'Vendedor:',
                'receipt_id' => 'ID del recibo:',
                'game' => 'Juego',
                'balls' => 'Bolas',
                'amount' => 'Cantidad',
                'total' => 'Total:',
                'total_amount' => 'Cantidad total:',
            ],
            'fr' => [
                'date' => 'Date:',
                'seller' => 'Vendeur:',
                'receipt_id' => 'ID de reçu:',
                'game' => 'Jeu',
                'balls' => 'Boules',
                'amount' => 'Montant',
                'total' => 'Total:',
                'total_amount' => 'Montant total:',
            ],
            'ht' => [
                'date' => 'Dat:',
                'seller' => 'Vann:',
                'receipt_id' => 'Resi Id:',
                'game' => 'Jwèt',
                'balls' => 'Boul',
                'amount' => 'Kantite lajan',
                'total' => 'Total:',
                'total_amount' => 'Kantite lajan total:',
            ],
        ];

        // Default to 'en' if $data['lang'] is not set or empty
        $lang = $data['lang'] ?? 'en';

        // Use the default English language translations if the given language does not exist
        $trans = $translations[$lang] ?? $translations['en'];
    @endphp
    <table cellpadding="5px" cellspacing="5px">
        <tr>
            <th width="40%" align="left">{{ $trans['date'] }}</th>
            <td>{{ \Carbon\Carbon::parse($data['lotteryData']['adddatetime'])->format('M d, Y h:i A') }}</td>
        </tr>
        <tr>
            <th width="40%" align="left">{{ $trans['seller'] }}</th>
            <td>{{ $seller['username'] ?? '' }} ({{ str_pad($seller['user_id'] ?? '', 6, '0', STR_PAD_LEFT) }})</td>
        </tr>
        <tr>
            <th width="40%" align="left">{{ $trans['receipt_id'] }}</th>
            <td>{{ $data['lotteryData']['nine_order_id'] }}</td>
        </tr>
    </table>

    <table class="table1" cellspacing="0" cellpadding="5px">
        <tr class="height1">
            <td>{{ $trans['game'] }}</td>
            <td align="center">{{ $trans['balls'] }}</td>
            <td align="right">{{ $trans['amount'] }}</td>
        </tr>

        @php
            $groupedItems = collect($data['lotteryData']['orderItems'])->groupBy('product_id');
            $totalAmount = 0;
        @endphp

        @foreach ($groupedItems as $productId => $items)
            <tr>
                <td></td>
                <td align="center">
                    <h3>{{ $items->first()['product_name'] }}</h3>
                </td>
                <td></td>
            </tr>
            @foreach ($items as $item)
                <tr class="height"
                    style="background-color: {{ $item['winning_amount'] > 0 ? '#ffcccc' : 'transparent' }};">
                    <td>
                        @if ($item['lot_type'] == 'BOR')
                            Borlette
                        @elseif($item['lot_type'] == 'MAR')
                            Mariage
                        @else
                            {{ $item['lot_type'] }}
                        @endif
                    </td>
                    <td align="center">{{ $item['lot_number'] }}</td>
                    <td align="right">
                        @if ($item['is_free'] == 0)
                            @if ($item['lot_frac'] != 0)
                                {{ $item['lot_frac'] }}
                                <span>{{ $item['winning_amount'] != 0 ? $item['winning_amount'] : '' }}</span>
                            @else
                                <span>{{ $item['winning_amount'] != 0 ? $item['winning_amount'] : '******' }}
                            @endif
                        @else
                            <span>{{ $item['winning_amount'] != 0 ? $item['winning_amount'] : '******' }}
                        @endif
                    </td>
                </tr>
                @php
                    $totalAmount += $item['winning_amount'];
                @endphp
            @endforeach
        @endforeach

        <tr class="height1">
            <td>{{ $trans['total'] }}</td>
            <td></td>
            <td align="right">Gdes.{{ $data['lotteryData']['grand_total'] }}</td>
        </tr>
        @if ($totalAmount != 0)
            <tr class="height1">
                <td>{{ $trans['total_amount'] }}</td>
                <td></td>
                <td align="right">Gdes {{ $totalAmount }}</td>
            </tr>
        @endif
    </table>
    <center>
        <div class="">
            <img src="{{ $data['qrCode'] }}" alt="QR Code">
        </div>
        @if ($adminUser->company_footer != null)
            <center style="margin-bottom: 30px;" class="">
                <strong>{!! nl2br(e(str_replace(',', "\n", $adminUser->company_footer))) !!}</strong>
            </center>
        @endif
        <div class="">
            Powered by: <span>thewebconcept.com</span>
            <br>
            Contact: <span>+92-345-7573667</span>
        </div>
    </center>
</body>

</html>


<style>
    /* For print-specific styling */
    @media print {
        .company-header {
            font-size: 30px !important;
            /* Enlarge the font size during print to see the effect */
        }

        /* If email is obfuscated using a script, this forces display */
        .company-header::before {
            content: attr(data-email);
            visibility: visible !important;
            display: block;
        }

        /* Ensure content inside the company header is fully visible */
        .company-header {
            visibility: visible !important;
            word-wrap: break-word;
        }
    }
</style>

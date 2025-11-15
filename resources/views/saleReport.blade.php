@php
// Define the translations for headings and labels
$translations = [
    'en' => [
        'sale_report' => 'Sale Report',
        'date' => 'Date:',
        'user' => 'User:',
        'lottery_name' => 'Lottery Name:',
        'total_receipts' => 'Total Receipts',
        'total_sold' => 'Total Sold',
        'total_purchased' => 'Total Purchased',
        'winnings_receipts' => 'Winnings Receipts',
        'winning_total' => 'Winning Total',
        'commission' => 'Commission',
        'pnl' => 'PNL',
        'advance' => 'Advance',
        'balance' => 'Balance',
        'manager_name' => 'Manager Name',
        'manager_commission' => 'Manager Commission',
        'total_deposit' => 'Total Deposit',
        'total_withdraw' => 'Total Withdraw',
    ],
    'es' => [
        'sale_report' => 'Informe de ventas',
        'date' => 'Fecha:',
        'user' => 'Usuario:',
        'lottery_name' => 'Nombre de la lotería:',
        'total_receipts' => 'Recibos totales',
        'total_sold' => 'Total vendido',
        'total_purchased' => 'Total comprado',
        'winnings_receipts' => 'Recibos ganadores',
        'winning_total' => 'Total ganador',
        'commission' => 'Comisión',
        'pnl' => 'PNL',
        'advance' => 'Adelanto',
        'balance' => 'Saldo',
        'manager_name' => 'Nombre del administrador',
        'manager_commission' => 'Comisión Gestora',
        'total_deposit' => 'Depósito total',
        'total_withdraw' => 'Retiro total',
    ],
    'fr' => [
        'sale_report' => 'Rapport de vente',
        'date' => 'Date:',
        'user' => 'Utilisateur:',
        'lottery_name' => 'Nom de la loterie:',
        'total_receipts' => 'Total des reçus',
        'total_sold' => 'Total vendu',
        'total_purchased' => 'Total acheté',
        'winnings_receipts' => 'Reçus gagnants',
        'winning_total' => 'Total des gains',
        'commission' => 'Commission',
        'pnl' => 'PNL',
        'advance' => 'Avance',
        'balance' => 'Solde',
        'manager_name' => 'Nom du gestionnaire',
        'manager_commission' => 'Commission de gestionnaire',
        'total_deposit' => 'Dépôt total',
        'total_withdraw' => 'Retrait total',
    ],
    'ht' => [
        'sale_report' => 'Rapò vant',
        'date' => 'Dat:',
        'user' => 'Itilizatè:',
        'lottery_name' => 'Non lotri a:',
        'total_receipts' => 'Resi total',
        'total_sold' => 'Total vann',
        'total_purchased' => 'Total achte',
        'winnings_receipts' => 'Resi genyen',
        'winning_total' => 'Total genyen',
        'commission' => 'Komisyon',
        'pnl' => 'PNL',
        'advance' => 'Avans',
        'balance' => 'Balans',
        'manager_name' => 'Non Manadjè',
        'manager_commission' => 'Manadjè Komisyon',
        'total_deposit' => 'Depo total',
        'total_withdraw' => 'Retire total',
    ],
];



// Default to 'en' if $lang is not set or empty
$lang = $lang ?? 'en';

// Use the default English language translations if the given language does not exist
$trans = $translations[$lang] ?? $translations['en'];
$userRole = auth()->user()->user_role;
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <title>{{ $trans['sale_report'] }}</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <style>
        /* Hide the print button when printing */
        @media print {
            button {
                display: none;
            }
        }
        
        table{
            border: 2px solid black !important;
        }
        
        th, td{
            border: 1px solid black !important;
        }
        
        button{
            padding: 15px;
            margin: 50px;
            color: white;
            background-color: darkblue;
            border: none;
            cursor:pointer;
            border-radius: 5px;
        }
    </style>
</head>
<body style="font-size: 30px">
    <!--<button class="" onclick="window.print()">Print</button>-->
    
    @if($key == 'single')
    <div class="container">
    <div class="row">
        <div class="col-12">
            <h2>{{ $trans['sale_report'] }}</h2>
        </div>
    </div>

    {{-- Variables to hold sums --}}
    @php
        $sumTotalReceipts = 0;
        $sumOrderTotalAmount = 0;
        $sumWinningNumbersTotal = 0;
        $sumWinnings = 0;
        $sumCommission = 0;
        $sumTotalSold = 0;
        $sumAdvance = 0;
        $sumNewBalance = 0;
        $totalDeposit = 0;
        $totalWithdraw = 0;
        $sumPnL = 0;
        $sumNetBalance = 0;
    @endphp

    @foreach ($data as $username => $userDataByDate)
    @php
        // Get the first and last dates for this user
        $dates = array_keys($userDataByDate);
        $startDate = reset($dates);
        $endDate = end($dates);
    @endphp
    
    @foreach ($userDataByDate as $currentDate => $userData)
        <!--<p>User Role: {{ $userData['commission'] }}</p>-->
        @php
            $totalSold = $userData['orderTotalAmount'] + $userData['totalDeposit'] - $userData['totalWithdraw'] - $userData['winnings'];
            $totalSold -= $userData['commission'];
            
            $newBalance = 0;
            
            if ($totalSold < 0) {
                $newBalance = $userData['advance'] + $totalSold; // Adding since totalSold is negative
            } else if($totalSold > 0) {
                $newBalance = $totalSold + $userData['advance'];
            } else {
                $newBalance = $totalSold;
            }
        
            // Accumulate sums
            $sumTotalReceipts += $userData['totalReceipts'];
            $sumOrderTotalAmount += $userData['orderTotalAmount'];
            $sumWinningNumbersTotal += $userData['winningNumbersTotal'];
            $sumWinnings += $userData['winnings'];
            $sumCommission += $userData['commission'];
            $sumTotalSold += $userData['totalSold'];
            $sumAdvance += $userData['advance'];
            $sumNewBalance += 0;
            $totalDeposit += $userData['totalDeposit'];
            $totalWithdraw += $userData['totalWithdraw'];
        
            // ✅ Keep numeric
            $netBalance = $sumTotalSold + $totalDeposit - $totalWithdraw - $sumCommission - $sumWinnings;
            $Pnl = $netBalance - $userData['advance'];
        
            $sumPnL += $Pnl;
            $sumNetBalance += $netBalance;
        
            // ✅ Format only when displaying in HTML, e.g. {{ number_format($netBalance, 2) }}
        @endphp
            <div class="row"  style="margin-top: 30px;">
                <div class="col-12">
                    <h3>{{ $trans['user'] }} {{ $username }} ({{ $trans['date'] }} {{ $currentDate }})</h3>
                    <p>{{ $trans['lottery_name'] }} 
                        @foreach ($userData['lotteryName'] as $name)
                            {{ $name }}{{ !$loop->last ? ', ' : '' }}
                        @endforeach
                    </p>
                       
                    <table class="table table-bordered table-hover">
                        <tr>
                            <th>{{ $trans['total_receipts'] }}</th>
                            <td>{{ $userData['totalReceipts'] }}</td>
                            <th>
                                @if($userRole === 'customer' || isset($userData['user_role']) === 'customer')
                                    {{ $trans['total_purchased'] }}
                                @else
                                    {{ $trans['total_sold'] }}
                                @endif
                            </th>
                            
                            <td>{{ number_format($userData['totalSold'], 2) }}</td>
                        </tr>
                        <tr>
                            <th>{{ $trans['winnings_receipts'] }}</th>
                            <td>{{ $userData['winningNumbersTotal'] }}</td>
                            <th>{{ $trans['winning_total'] }}</th>
                            <td>{{ number_format($userData['winnings'], 2) }}</td>
                        </tr>
                        
                        <tr>
                            <th>{{ $trans['total_deposit'] }}</th>
                            <td>{{ number_format($userData['totalDeposit'], 2) }}</td>
                            <th>{{ $trans['total_withdraw'] }}</th>
                            <td>{{ number_format($userData['totalWithdraw'], 2) }}</td>
                        </tr>
                        @if( isset($userData['user_role']) !== 'customer')
                        <tr>
                            <th>{{ $trans['commission'] }}</th>
                            <td>{{ number_format($userData['commission'], 2) }}</td>
                            <th>{{ $trans['pnl'] }}</th>
                            <td>{{ number_format($totalSold, 2) }}</td>
                        </tr>
                        <tr>
                            <th>{{ $trans['advance'] }}</th>
                            <td>{{ number_format($sumAdvance, 2) }}</td>
                            <th>{{ $trans['balance'] }}</th>
                            <td>{{ number_format($newBalance, 2) }}</td>
                        </tr>
                    @else
                        <tr>
                            <th>{{ $trans['pnl'] }}</th>
                            <td>{{ number_format($Pnl, 2) }}</td>
                            <th>{{ $trans['balance'] }}</th>
                            <td>{{ number_format($netBalance, 2) }}</td>
                        </tr>
                    @endif
                    </table>
                </div>
            </div>
    @endforeach
@endforeach
    @php
    if($netBalance < 0){
        $sumNewBalance += $sumAdvance + $netBalance;
    }else if($netBalance > 0){
        $sumNewBalance += $sumAdvance + $netBalance;
    }else{
        $sumNewBalance += $netBalance;
    }

    @endphp
    {{-- Summary Table --}}
    <div class="row" style="margin-top: 50px;">
        <div class="col-12">
            <h3>Summary</h3>
            <table class="table table-bordered table-hover">
                <tr>
                    <th>{{ $trans['total_receipts'] }}</th>
                    <td>{{ number_format($sumTotalReceipts, 2) }}</td>
                    <th>
                        @if($userRole === 'customer' || isset($userData['user_role']) === 'customer')
                            {{ $trans['total_purchased'] }}
                        @else
                            {{ $trans['total_sold'] }}
                        @endif
                    </th>

                    <td>{{ number_format($sumTotalSold, 2) }}</td>
                </tr>
                <tr>
                    <th>{{ $trans['winnings_receipts'] }}</th>
                    <td>{{ number_format($sumWinningNumbersTotal, 2) }}</td>
                    <th>{{ $trans['winning_total'] }}</th>
                    <td>{{ number_format($sumWinnings, 2) }}</td>
                </tr>
                @if( isset($userData['user_role']) !== 'customer')
                <tr>
                        <th>{{ $trans['total_deposit'] }}</th>
                        <td>{{ number_format($totalDeposit, 2) }}</td>
                        <th>{{ $trans['total_withdraw'] }}</th>
                        <td>{{ number_format($totalWithdraw, 2) }}</td>
                    </tr>
                    <tr>
                        <th>{{ $trans['commission'] }}</th>
                        <td>{{ number_format($sumCommission, 2) }}</td>
                        <th>{{ $trans['pnl'] }}</th>
                        <td>{{ number_format($netBalance, 2) }}</td>
                    </tr>
                    <tr>
                        <th>{{ $trans['advance'] }}</th>
                        <td>{{ number_format($sumAdvance, 2) }}</td>
                        <th>{{ $trans['balance'] }}</th>
                        <td>{{ number_format($sumNewBalance, 2) }}</td>
                    </tr>
                @else
                    <th>{{ $trans['pnl'] }}</th>
                    <td>{{ number_format($sumPnL, 2) }}</td>
                    <th>{{ $trans['balance'] }}</th>
                    <td>{{ number_format($sumNetBalance, 2) }}</td>
                @endif
            </table>
        </div>
    </div>
</div>

    @else
<div class="container">
    <div class="row">
        <div class="col-12">
            <h2>{{ $trans['sale_report'] }}</h2>
            <p>{{ $trans['date'] }} {{ $data[array_key_first($data)]['date'] }}</p>
        </div>
    </div>

    @php
        // Initialize totals
        $totalReceiptsAll = 0;
        $totalOrderAmountAll = 0;
        $totalWinningsAll = 0;
        $totalCommissionAll = 0;
        $totalManagerCommissionAll = 0;
        $totalAdvanceAll = 0;
        $totalPnLAll = 0;
        $totalBalanceAll = 0;
        $totalDepositAll = 0;
        $totalwithDrawAll = 0;
    @endphp

    
    @foreach ($data as $username => $userData)
        @php
            @$userRole = $userData['user_role'];

            $managerCommissionPercentage = isset($userData['managerData']->commission) ? $userData['managerData']->commission : 0;
            $sellerCommissionPercentage = $userData['sellerCommission'];
            $commissionDifference = $managerCommissionPercentage - $sellerCommissionPercentage;
            $managerCommission = ($userData['totalSold'] / 100) * $commissionDifference;
            $totalSold = $userData['totalSold'] + $userData['totalDeposit'] - $userData['totalWithdraw'] - $userData['winnings'] - $userData['commission'] - $managerCommission;

            if ($totalSold < 0) {
                $newBalance = $userData['advance'] + $totalSold;
            } else if ($totalSold > 0) {
                $newBalance = $totalSold + $userData['advance'];
            } else {
                $newBalance = $totalSold;
            }

            // Accumulate totals
            $totalReceiptsAll += $userData['totalReceipts'];
            $totalOrderAmountAll += $userData['orderTotalAmount'];
            $totalWinningsAll += $userData['winnings'];
            $totalCommissionAll += $userData['commission'];
            $totalManagerCommissionAll += $managerCommission;
            $totalAdvanceAll += $userData['advance'];
            if(isset($userData['user_role']) == 'customer'){
                @$totalDepositAll += $userData['totalDeposit'];
                @$totalwithDrawAll += $userData['totalWithdraw'];
            }
            
            $totalBalanceAll = $totalOrderAmountAll + $totalDepositAll - $totalCommissionAll - $totalWinningsAll - $totalwithDrawAll;
            $totalPnLAll = $totalBalanceAll - $totalAdvanceAll;
           //dd($userData['balance']);
        @endphp
        @if(isset($userData['user_role']) && $userData['user_role'] != 'customer')
        <div class="row">
            <div class="col-12">
                <h3>{{ $trans['user'] }} {{ ucwords($username) }}</h3>
                <p>{{ $trans['lottery_name'] }} 
                    @foreach ($userData['lotteryName'] as $name)
                        {{ ucwords($name) }},
                    @endforeach
                </p>

                <table class="table table-bordered table-hover">
                    <tr>
                        <th>{{ $trans['total_receipts'] }}</th>
                        <td>{{ $userData['totalReceipts'] }}</td>
                        <th>{{ $trans['total_sold'] }}</th>
                        <td>{{ number_format($userData['totalSold'], 2) }}</td>
                    </tr>
                    <tr>
                        <th>{{ $trans['winnings_receipts'] }}</th>
                        <td>{{ $userData['winningNumbersTotal'] }}</td>
                        <th>{{ $trans['winning_total'] }}</th>
                        <td>{{ number_format($userData['winnings'], 2) }}</td>
                    </tr>

                    @if($userRole !== 'customer')
                        <tr>
                            <th>{{ $trans['commission'] }}</th>
                            <td>{{ number_format($userData['commission'], 2) }}</td>
                            <th>{{ $trans['manager_commission'] }}</th>
                            <td>{{ number_format($managerCommission, 2) }}</td>
                        </tr>
                        <tr>
                            <th>{{ $trans['total_deposit'] }}</th>
                            <td>{{ number_format($userData['totalDeposit'], 2) }}</td>
                            <th>{{ $trans['total_withdraw'] }}</th>
                            <td>{{ number_format($userData['totalWithdraw'], 2) }}</td>
                        </tr>
                        <tr>
                            <th></th>
                            <td></td>
                            <th>{{ $trans['pnl'] }}</th>
                            <td>{{ number_format($totalSold, 2) }}</td>
                        </tr>
                        <tr>
                            <th></th>
                            <td></td>
                            <th>{{ $trans['advance'] }}</th>
                            <td>{{ number_format($userData['advance'], 2) }}</td>
                        </tr>
                        <tr>
                            <th></th>
                            <td></td>
                            <th>{{ $trans['balance'] }}</th>
                            <td>{{ number_format($newBalance, 2) }}</td>
                        </tr>
                    @else
                        <tr>
                            <th>{{ $trans['pnl'] }}</th>
                            <td>{{ number_format($totalPnLAll, 2) }}</td>
                            <th>{{ $trans['balance'] }}</th>
                            <td>{{ number_format($totalBalanceAll, 2) }}</td>
                        </tr>
                    @endif
                </table>
            </div>
        </div>
        @else
        @endif
    @endforeach
@if( $userRole=='customer' )
    {{-- ✅ Summary Table --}}
    <div class="row mt-4">
        <div class="col-12">
            <h2>Total Summary</h2>
            <table class="table table-bordered table-hover">
                <tr>
                    <th>{{ $trans['total_receipts'] }}</th>
                    <td>{{ $totalReceiptsAll }}</td>
                    <th>{{ $trans['total_sold'] }}</th>
                    <td>{{ number_format($totalOrderAmountAll, 2) }}</td>
                </tr>
                <tr>
                    <th>{{ $trans['winnings_receipts'] }}</th>
                    <td>{{ $userData['winningNumbersTotal'] }}</td>
                    <th>{{ $trans['winning_total'] }}</th>
                    <td>{{ number_format($totalWinningsAll, 2) }}</td>
                </tr>
                <tr>
                    <th>{{ $trans['total_deposit'] }}</th>
                    <td>{{ $totalDepositAll }}</td>
                    <th>{{ $trans['total_withdraw'] }}</th>
                    <td>{{ number_format($totalwithDrawAll, 2) }}</td>
                </tr>
                <tr>
                    <th>{{ $trans['pnl'] }}</th>
                    <td>{{ number_format($totalPnLAll, 2) }}</td>
                    <th>{{ $trans['balance'] }}</th>
                    <td>{{ number_format($totalBalanceAll, 2) }}</td>
                </tr>
            </table>
        </div>
    </div>
</div>
@endif
<!--{{$userRole}}-->
    @php
        if( $userRole!='customer' )
        {
    @endphp
    <h2>Total Summary</h2>
        @php
            $managerTotals = [];
            foreach ($data as $username => $userData) {
                $managerName = @$userData['managername'];
                //dd($userData);
                // Manager's commission percentage (from managerData)
                $managerCommissionPercentage = isset($userData['managerData']->commission) ? $userData['managerData']->commission : 0;
    
                // Seller's commission percentage
                $sellerCommissionPercentage = $userData['sellerCommission'];
    
                // Calculate the difference in commission percentages
                $commissionDifference = $managerCommissionPercentage - $sellerCommissionPercentage;
    
                // Calculate manager's commission on the seller's total sold amount (using the commission difference)
                $managerCommission = ($userData['totalSold'] / 100) * $commissionDifference;
                
                // Calculate seller's total sold amount
                $totalSold = $userData['totalSold'] + $userData['totalDeposit'] - $userData['totalWithdraw'] - $userData['winnings'] - $userData['commission'] - $managerCommission;
                
                // Calculate seller's balance after considering advances
                $newBalance = 0;
                if ($totalSold < 0) {
                                $newBalance = $userData['advance'] + $totalSold; // Adding since totalSold is negative
                            }else if($totalSold > 0){
                                $newBalance = $totalSold + $userData['advance'];
                            }
                            else {
                                $newBalance = $totalSold;
                            }
        
                if (!isset($managerTotals[$managerName])) {
                    $managerTotals[$managerName] = [
                        'totalSold' => 0,
                        'totalSellerCommission' => 0,
                        'totalManagerCommission' => 0, // New key for Manager Commission
                        'totalReceipts' => 0,
                        'winningReceipts' => 0,
                        'totalWinnings' => 0,
                        'totalOrderAmount' => 0,
                        'totalAdvance' => 0,
                        'totalBalance' => 0,
                        'pnl' => 0,
                        'sumTotalDeposit' => 0,
                        'sumTotalWithdraw' => 0,
                    ];
                }
        
                $managerTotals[$managerName]['totalSold'] += $userData['totalSold'];
                $managerTotals[$managerName]['totalSellerCommission'] += $userData['commission'];
                $managerTotals[$managerName]['totalManagerCommission'] += $managerCommission; // Track Manager Commission
                $managerTotals[$managerName]['totalReceipts'] += $userData['totalReceipts'];
                $managerTotals[$managerName]['winningReceipts'] += $userData['winningNumbersTotal'];
                $managerTotals[$managerName]['totalWinnings'] += $userData['winnings'];
                $managerTotals[$managerName]['totalOrderAmount'] += $userData['orderTotalAmount'];
                $managerTotals[$managerName]['totalAdvance'] += $userData['advance'];
                $managerTotals[$managerName]['pnl'] += $userData['orderTotalAmount'] - $userData['commission'];
                $managerTotals[$managerName]['totalBalance'] += $newBalance; // Consider this the final balance after advances
                $managerTotals[$managerName]['sumTotalDeposit'] += $userData['totalDeposit'];
                $managerTotals[$managerName]['sumTotalWithdraw'] += $userData['totalWithdraw'];
            }
        }
        
    @endphp
    @if($userRole!='customer')
    <div class="row">
        <div class="col-12">
            <table id="supervisorTable" class="table table-bordered table-hover">
                <thead>
                    <tr>
                        <th>{{ $trans['manager_name'] }}</th>
                        <th>{{ $trans['total_sold'] }}</th>
                        <th>{{ $trans['commission'] }}</th> <!-- Manager's Commission -->
                    </tr>
                </thead>
                <tbody>
                    @foreach ($managerTotals as $managerName => $totals)
                        <tr>
                            <td>{{ $managerName }}</td>
                            <td>{{ number_format($totals['totalSold'], 2) }}</td>
                            <td>{{ number_format($totals['totalManagerCommission'], 2) }}</td> <!-- Display Manager Commission -->
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <h2>Manager Summary</h2>
    <div class="row">
        <div class="col-12">
                    @foreach ($managerTotals as $managerName => $totals)
            <table id="summaryTable" class="table table-bordered table-hover">
                <thead>
                    @php
                    $sumTotalSold = $totals['totalSold'] + $totals['sumTotalDeposit'] - $totals['totalWinnings'] - $totals['totalSellerCommission'] - $totals['totalManagerCommission'] - $totals['sumTotalWithdraw'];
                        $sumNewBalance = 0;
                        if($sumTotalSold < 0){
                            $sumNewBalance = $totals['totalAdvance'] + $sumTotalSold;
                        }else if($sumTotalSold > 0){
                            $sumNewBalance = $sumTotalSold + $totals['totalAdvance'];
                        }else{
                            $sumNewBalance = $sumTotalSold;
                        }
                    @endphp
                    <tr>
                        <th>{{ $trans['manager_name'] }}</th>
                        <td>{{ $managerName }}</td>
                        <th>{{ $trans['total_receipts'] }}</th>
                        <td>{{ number_format($totals['totalReceipts'], 0) }}</td>
                    </tr>
                    <tr>
                        <th>{{ $trans['total_sold'] }}</th>
                        <td>{{ number_format($totals['totalSold'], 2) }}</td>
                        <th>{{ $trans['winnings_receipts'] }}</th>
                        <td>{{ number_format($totals['winningReceipts'], 2) }}</td>
                    </tr>
                    <tr>
                        <th>{{ $trans['winning_total'] }}</th>
                        <td>{{ number_format($totals['totalWinnings'], 2) }}</td>
                        <th>{{ $trans['commission'] }}</th>
                        <td>{{ number_format($totals['totalSellerCommission'], 2) }}</td>
                    </tr>
                    <tr>
                        <th>{{ $trans['manager_commission'] }}</th>
                        <td>{{ number_format($totals['totalManagerCommission'], 2) }}</td> <!-- Display Manager Commission -->
                        <th>{{ $trans['total_deposit'] }}</th>
                        <td>{{ number_format($totals['sumTotalDeposit'], 2) }}</td>
                    </tr>
                    <tr>
                        <th>{{ $trans['total_withdraw'] }}</th>
                        <td>{{ number_format($totals['sumTotalWithdraw'], 2) }}</td> <!-- Display Manager Commission -->
                        <th>{{ $trans['pnl'] }}</th>
                        <td>{{ number_format($sumTotalSold, 2) }}</td>
                    </tr>
                    <tr>
                        <th>{{ $trans['advance'] }}</th>
                        <td>{{ number_format($totals['totalAdvance'], 2) }}</td>
                        <th>{{ $trans['balance'] }}</th>
                        <td>{{ number_format($sumNewBalance, 2) }}</td>
                    </tr>
                </thead>
            </table>
                    @endforeach
        </div>
    </div>
    @endif
</div>

    @endif
    <!-- Bootstrap JS and dependencies -->
    <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
</body>
</html>

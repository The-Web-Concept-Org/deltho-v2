@include('include.header')
<style>
    .text-red {
        color: red;
    }
.border {
    border: 1px solid #ddd; /* Adjust the color and style as needed */
}
</style>

<div class=" bg-white w-full rounded-2xl shadow-lg">
    <div class=" flex justify-between p-3 text-white rounded-t-2xl">
        <div class=" text-xl font-semibold">
            <h4 style="color: black;">Sale Report</h4>
        </div>
        <div>

        </div>
    </div>
    <div class="py-4">
        <form id="saleReportForm" action="/saleReport" method="post" enctype="multipart/form-data">
            @csrf
            <div class="grid gap-4 mb-6 mx-4 md:grid-cols-4">
                <div>
                    <div class="max-w-sm mx-auto">
                        @if(session('user_details')['user_role'] == 'superadmin')
                        <label for="selec_user" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Select an admin</label>
                        @elseif(session('user_details')['user_role'] == 'admin')
                        <label for="selec_user" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Select a manager</label>
                        @elseif(session('user_details')['user_role'] == 'manager')
                        <label for="selec_user" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Select user</label>
                        @endif
                        <select id="selec_user" name="admin_id" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                            <option selected>Select user</option>
                            @foreach($users as $user)
                            <option value="{{$user->user_id}}">{{$user->username}} ({{$user->user_role}})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <div class="max-w-sm mx-auto">
                        @if(session('user_details')['user_role'] == 'superadmin')
                        <label for="selec_manager" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Select a manager or seller</label>
                        @elseif(session('user_details')['user_role'] == 'admin')
                        <label for="selec_manager" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Select a manager or seller</label>
                        @elseif(session('user_details')['user_role'] == 'manager')
                        <label for="selec_manager" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">Select a seller</label>
                        @endif
                        <select id="selec_manager" name="manager_ids[]" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500">
                            <option selected>Select manager</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="fromDate" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">From Date</label>
                    <input type="date" id="fromDate" name="fromdate" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500" placeholder="John" required />
                </div>
                <div>
                    <label for="toDate" class="block mb-2 text-sm font-medium text-gray-900 dark:text-white">toDate</label>
                    <input type="date" id="toDate" name="todate" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500" placeholder="John" required />
                </div>
                <div></div>
                <div></div>
                <div></div>
                <div class="col-span-4">
                    <div class="text-right">
                        <button type="submit" class="text-white bg-blue-700 hover:bg-blue-800 focus:ring-4 focus:ring-blue-300 font-medium rounded-lg text-sm px-5 py-2.5 me-2 mb-2 dark:bg-blue-600 dark:hover:bg-blue-700 focus:outline-none dark:focus:ring-blue-800">Submit</button>
                    </div>
                </div>
            </div>
        </form>
        <div class="text-right p-6">
            <a href="javascript:void(0);" onclick="printPageArea('printableArea')">
                <button class=" bg-blue-700 py-2 px-4 text-white rounded-md">
                    Print
                </button>
            </a>
        </div>
    </div>
</div>
<div id="printableArea">
    <div class=" bg-white w-full rounded-2xl shadow-lg">
    <div class="pb-4">
    <div class="relative overflow-x-auto">
            <table class="w-full text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                    <tr class="text-center">
                        <th scope="col" class=" py-3 border">Name</th>
                        <th scope="col" class=" py-3 border">Total Receipts</th>
                        <th scope="col" class=" py-3 border">Total Sold</th>
                        <th scope="col" class=" py-3 border">Winning Receipts</th>
                        <th scope="col" class=" py-3 border">Winning Total</th>
                        <th scope="col" class=" py-3 border">Commission</th>
                        <th scope="col" class=" py-3 border">Supervisor Commission</th>
                        <th scope="col" class=" py-3 border">PNL</th>
                        <th scope="col" class=" py-3 border">Advance</th>
                        <th scope="col" class=" py-3 border">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data will be dynamically appended here -->
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class=" bg-white w-full rounded-2xl shadow-lg">
    <div class="pb-4">
        <div class="my-3">
            <div class="relative overflow-x-auto">
                <table class="w-full text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                        <tr class="text-center">
                            <th scope="col" class=" py-3 border">Manager Name</th>
                            <th scope="col" class=" py-3 border">Total sold</th>
                            <th scope="col" class=" py-3 border">Total Commission</th>
                        </tr>
                    </thead>
                    <tbody id="managerTable">
                        <!-- Data will be dynamically appended here -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<div class=" bg-white w-full rounded-2xl shadow-lg">
    <div class="pb-4 mt-4">
        <div class="relative overflow-x-auto">
            <table class="w-full text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                    <tr class="text-center">
                        <th scope="col" class=" py-3 border">Name</th>
                        <th scope="col" class=" py-3 border">Total Receipts</th>
                        <th scope="col" class=" py-3 border">Total Sold</th>
                        <th scope="col" class=" py-3 border">Winning Receipts</th>
                        <th scope="col" class=" py-3 border">Winning Total</th>
                        <th scope="col" class=" py-3 border">Commission</th>
                        <th scope="col" class=" py-3 border">Supervisor Commission</th>
                        <th scope="col" class=" py-3 border">PNL</th>
                        <th scope="col" class=" py-3 border">Advance</th>
                        <th scope="col" class=" py-3 border">Balance</th>
                    </tr>
                </thead>
                <tbody id="managerTotalTable">
                    <!-- Data will be dynamically appended here -->
                </tbody>
            </table>
        </div>
        
    </div>
</div>
</div>
@include('include.footer')
<script>
    $(document).ready(function() {
        $('#selec_user').on('change', function() {
            var adminId = $(this).val();

            // Clear the manager dropdown
            $('#selec_manager').empty().append('<option selected>Select manager</option>');

            if (adminId) {
                $.ajax({
                    url: '/getManagers/' + adminId, // URL to fetch managers and sellers
                    type: 'GET',
                    success: function(data) {
                        $('#selec_manager').append('<option value="">all</option>');
                        $.each(data, function(key, value) {
                            $('#selec_manager').append('<option value="' + value.id + '">' + value.username + ' (' + value.role + ')</option>');
                        });
                    },
                    error: function(error) {
                        console.log('Error:', error);
                    }
                });
            }
        });
        $('#selec_manager').on('change', function() {
            var selectedValue = $(this).val();

            if (selectedValue == "") {
                // If 'all' is selected, clear the manager IDs array
                $('#selec_manager').val(null); // Clear the selected options
            }
        });
        // Handle form submission and table update
        // Handle form submission and table update
        $('form').on('submit', function(event) {
    event.preventDefault(); // Prevent the default form submission

    var formData = $(this).serialize(); // Serialize form data

    $.ajax({
        url: $(this).attr('action'), // Get form action URL
        type: 'POST',
        data: formData,
        success: function(response) {
            // Check if the response is successful
            console.log(response);
            if (response.success) {
                // Clear the table body before appending new rows
                $('.relative tbody').empty();
                
                // Create a dictionary to store manager data
                var managerData = {};

                // Iterate over the response data and append rows
                $.each(response.data, function(name, details) {
                    // Convert values to numbers
                    var orderTotalAmount = parseFloat(details.orderTotalAmount);
                    var winnings = parseFloat(details.winnings);
                    var commission = parseFloat(details.commission);
                    var advance = parseFloat(details.advance);

                    // Get manager's commission and subtract seller's commission
                    var managerCommission = parseFloat(details.managerData.commission);
                    var sellerCommission = parseFloat(details.sellerCommission);
                    var remainingManagerCommission = managerCommission - sellerCommission;

                    // Calculate the percentage of the manager's remaining commission based on the orderTotalAmount
                    var commissionPercentage = (orderTotalAmount / 100) * remainingManagerCommission;

                    // Calculate totalSold
                    var totalSold = orderTotalAmount - winnings;

                    // Adjust totalSold based on commission
                    totalSold -= commission;
                    
                    totalSold -= commissionPercentage

                    // Calculate new balance
                    var newBalance;
                    if (totalSold < 0) {
                        newBalance = advance + totalSold; // Adding since totalSold is negative
                    }else if(totalSold > 0){
                        newBalance = totalSold - advance;
                    }
                    else {
                        newBalance = totalSold;
                    }

                    // Determine if the row should be red based on negative values
                    var rowClass = (totalSold < 0 || commission < 0 || advance < 0 || newBalance < 0) ? 'text-red' : '';

                    // Append row with conditional styling
                    $('.relative tbody').append(`
                    <tr class="${rowClass} text-center">
                        <td class="border py-4">${name}</td>
                        <td class="border py-4">${details.totalReceipts}</td>
                        <td class="border py-4">${orderTotalAmount.toFixed(2)}</td>
                        <td class="border py-4">${details.winningNumbersTotal.toFixed(2)}</td>
                        <td class="border py-4">${winnings.toFixed(2)}</td>
                        <td class="border py-4">${commission.toFixed(2)}</td>
                        <td class="border py-4">${commissionPercentage.toFixed(2)}</td>
                        <td class="border py-4">${totalSold.toFixed(2)}</td>
                        <td class="border py-4">${advance.toFixed(2)}</td>
                        <td class="border py-4">${newBalance.toFixed(2)}</td>
                    </tr>
                    `);

                    // Check if the manager exists in the dictionary
                    if (!managerData[details.managername]) {
                        managerData[details.managername] = {
                            totalReceipts: 0,
                            orderTotalAmount: 0,
                            winningNumbersTotal: 0,
                            totalWinnings: 0,
                            totalSold: 0,
                            totalCommission: 0,
                            ManagerTotalCommission: 0,
                            totalAdvance: 0,
                            totalBalance: 0
                        };
                    }
                    
                    // Add the values of the current seller to the manager's total
                    managerData[details.managername].totalReceipts += parseFloat(details.totalReceipts);
                    managerData[details.managername].orderTotalAmount += orderTotalAmount;
                    managerData[details.managername].winningNumbersTotal += parseFloat(details.winningNumbersTotal);
                    managerData[details.managername].totalWinnings += winnings;
                    managerData[details.managername].totalSold += totalSold;
                    managerData[details.managername].totalCommission += commission;
                    managerData[details.managername].ManagerTotalCommission += commissionPercentage;
                    managerData[details.managername].totalAdvance += advance;
                    managerData[details.managername].totalBalance += newBalance;
                });

                // Clear the table body before appending new rows
                $('#managerTable').empty();
                $('#managerTotalTable').empty();

                // Iterate over the managerData dictionary and append rows to managerTable
                $.each(managerData, function(managerName, data) {
                    $('#managerTable').append(`
                        <tr class="border text-center">
                            <td class="border py-4">${managerName}</td>
                            <td class="border py-4">${data.orderTotalAmount.toFixed(2)}</td>
                            <td class="border py-4">${data.ManagerTotalCommission.toFixed(2)}</td>
                        </tr>
                    `);

                    // Determine if the row should be red based on negative values
                    var rowClass = (data.totalReceipts < 0 || data.orderTotalAmount < 0 || data.winningNumbersTotal < 0 || data.totalWinnings < 0 || data.totalCommission < 0 || data.totalSold < 0 || data.totalAdvance < 0 || data.totalBalance < 0) ? 'text-red' : '';

                    // Append rows to managerTotalTable
                    $('#managerTotalTable').append(`
                        <tr class="border text-center ${rowClass}">
                            <td class="border py-4">${managerName}</td>
                            <td class="border py-4">${data.totalReceipts.toFixed(2)}</td>
                            <td class="border py-4">${data.orderTotalAmount.toFixed(2)}</td>
                            <td class="border py-4">${data.winningNumbersTotal.toFixed(2)}</td>
                            <td class="border py-4">${data.totalWinnings.toFixed(2)}</td>
                            <td class="border py-4">${data.totalCommission.toFixed(2)}</td>
                            <td class="border py-4">${data.ManagerTotalCommission.toFixed(2)}</td>
                            <td class="border py-4">${data.totalSold.toFixed(2)}</td>
                            <td class="border py-4">${data.totalAdvance.toFixed(2)}</td>
                            <td class="border py-4">${data.totalBalance.toFixed(2)}</td>
                        </tr>
                    `);

                });
            } else {
                console.log('Error: Data not successfully returned.');
            }
        },
        error: function(error) {
            console.log('Error:', error);
        }
    });
});


    });
</script>
<script>
    function printPageArea(areaID) {
        var printContent = document.getElementById(areaID).innerHTML;
        var originalContent = document.body.innerHTML;

        // Create a style tag with the desired background color
        var style = document.createElement('style');
        style.innerHTML = 'body { background-color: white !important; }';

        // Append the style tag to the head of the document
        document.head.appendChild(style);

        // Set the body content to the print content and print
        document.body.innerHTML = printContent;
        window.print();

        // Restore the original content and remove the added style tag
        document.body.innerHTML = originalContent;
        document.head.removeChild(style);
    }
</script>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login - Horeca</title>
    <link href="https://deltholotto.com/assets/img/Deltho-Lott-White.png" rel="icon">
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
    <link href="https://cdn.jsdelivr.net/npm/flowbite@2.5.1/dist/flowbite.min.css" rel="stylesheet" />
    <style>
        body {
            background-image: linear-gradient(rgba(0, 0, 0, 0.519), rgba(0, 0, 0, 0.519)), url('{{ asset('assets/images/login-bg2.jpg') }}')
        }

        .form-login {
            height: 100%;
        }

        .rounded-med {
            border-radius: 1rem;
        }
    </style>
</head>

<body class="bg-white">
    <div class="h-screen w-full flex justify-center items-center ">
        <div
            class="bg-tranparent shadow-dark form-container sm:w-1/2 md:w-9/12 lg:w-1/2 flex flex-col md:flex-row items-center mx-5 sm:m-0 rounded-2xl overflow-hidden">
            <div class="bg-white w-full form-login md:w-1/2 flex flex-col items-center justify-center py-32 px-8 ">
                <div class=" block pb-1">
                    <img width="500" src="{{ asset('assets/images/logo.svg') }}" alt="">
                </div>
                <h3 class="text-3xl font-bold text-primary mb-4">
                    LOGIN
                </h3>
                <form id="login_data" method="post" class="w-full flex flex-col justify-center">
                    @csrf
                    <div class="mb-4">
                        <input type="email" placeholder="Email" name="email"
                            class="w-full p-3 rounded-med border placeholder-gray-400 focus:outline-none focus:border-green-600" />
                    </div>
                    <div class="mb-4">
                        <input type="password" placeholder="Password" name="password"
                            class="w-full p-3 rounded-med border placeholder-gray-400 focus:outline-none focus:border-green-600" />
                    </div>
                    <button class="bg-primary font-bold text-white focus:outline-none rounded-med p-3">
                        <div class=" text-center hidden" id="spinner">
                            <svg aria-hidden="true"
                                class="w-5 h-5 mx-auto text-center text-gray-200 animate-spin fill-primary"
                                viewBox="0 0 100 101" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M100 50.5908C100 78.2051 77.6142 100.591 50 100.591C22.3858 100.591 0 78.2051 0 50.5908C0 22.9766 22.3858 0.59082 50 0.59082C77.6142 0.59082 100 22.9766 100 50.5908ZM9.08144 50.5908C9.08144 73.1895 27.4013 91.5094 50 91.5094C72.5987 91.5094 90.9186 73.1895 90.9186 50.5908C90.9186 27.9921 72.5987 9.67226 50 9.67226C27.4013 9.67226 9.08144 27.9921 9.08144 50.5908Z"
                                    fill="currentColor" />
                                <path
                                    d="M93.9676 39.0409C96.393 38.4038 97.8624 35.9116 97.0079 33.5539C95.2932 28.8227 92.871 24.3692 89.8167 20.348C85.8452 15.1192 80.8826 10.7238 75.2124 7.41289C69.5422 4.10194 63.2754 1.94025 56.7698 1.05124C51.7666 0.367541 46.6976 0.446843 41.7345 1.27873C39.2613 1.69328 37.813 4.19778 38.4501 6.62326C39.0873 9.04874 41.5694 10.4717 44.0505 10.1071C47.8511 9.54855 51.7191 9.52689 55.5402 10.0491C60.8642 10.7766 65.9928 12.5457 70.6331 15.2552C75.2735 17.9648 79.3347 21.5619 82.5849 25.841C84.9175 28.9121 86.7997 32.2913 88.1811 35.8758C89.083 38.2158 91.5421 39.6781 93.9676 39.0409Z"
                                    fill="currentFill" />
                            </svg>
                        </div>
                        <div class="text-white  font-semibold" id="text">
                            Login
                        </div>
                    </button>
                </form>

            </div>
            <div class="w-full bg-primary form-login hidden md:flex  justify-center items-center text-white">
                <div>
                    <img width="100%" src="{{ asset('assets/images/login-vector2.png') }}" alt="">
                </div>

            </div>
        </div>
    </div>
    {{-- <script src="{{ asset('javascript/jquery.js') }}"></script> --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"
        integrity="sha512-v2CJ7UaYy4JwqLDIrZUI/4hqeoQieOmAZNXBeQyjo21dadnwR+8ZaIJVT8EE2iyI61OV8e6M8PP2/4hpQINQ/g=="
        crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/flowbite@2.5.1/dist/flowbite.min.js"></script>
    <script>
        $(document).ready(function() {
            $("#login_data").submit(function(event) {
                event.preventDefault();
                var formData = $(this).serialize();
                // Send the AJAX request
                $.ajax({
                    type: "POST",
                    url: "/login",
                    data: formData,
                    dataType: "json",
                    beforeSend: function() {
                        $('#spinner').removeClass('hidden');
                        $('#text').addClass('hidden');
                        $('#loginbutton').attr('disabled', true);
                    },
                    success: function(response) {
                        // Handle the success response here
                        if (response.success == true) {
                            $('#text').removeClass('hidden');
                            $('#spinner').addClass('hidden');
                            window.location.href = '/dashboard';


                        } else if (response.success == false) {
                            Swal.fire(
                                'Warning!',
                                response.message,
                                'warning'
                            )
                        }
                    },
                    error: function(jqXHR) {

                        let response = JSON.parse(jqXHR.responseText);

                        Swal.fire(
                            'Warning!',
                            response.message,
                            'warning'
                        )
                        $('#text').removeClass('hidden');
                        $('#spinner').addClass('hidden');
                        $('#loginbutton').attr('disabled', false);
                    }
                });
            });
        });
    </script>

</body>

</html>

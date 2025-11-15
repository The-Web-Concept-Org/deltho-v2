<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Confirmation</title>
</head>
<body>
    <p>Hello {{ $username }},</p>

    <p>Thank you for your request. We have received your information and will process it shortly. We will update you as soon as possible.</p>

    <p>Here are the details you provided:</p>

    <ul>
        <li><strong>Email:</strong> {{ $email }}</li>
        <li><strong>Phone:</strong> {{ $phone }}</li>
        <li><strong>User Role:</strong> {{ $userRole }}</li>
        <li><strong>Address:</strong> {{ $address }}</li>
    </ul>

    <p>Thank you for choosing us!</p>

    <p>Best regards,<br>Your Company Name</p>
</body>
</html>

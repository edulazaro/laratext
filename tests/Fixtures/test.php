<?php

use EduLazaro\Laratext\Text;

Text::get('key.simple.php', 'Simple PHP value');
Text::get("key.single.inside.php", "PHP with 'single' quotes inside");
Text::get('key.double.inside.php', 'PHP with "double" quotes inside');
Text::get('key.escaped.single.php', 'PHP with escaped \'single\' quotes');
text("key.helper", 'Helper function call');
text('key.nospace','No space in call');

$name = 'hey';
Text::get('key.welcome_user', 'Welcome, :name!', ['name' => $name]);

Text::get('key.items_in_cart', 'You have :count items in your cart, :name.', [
    'count' => 3,
    'name' => 'Edu',
]);

Text::get('key.file_uploaded', ':count file uploaded.', ['count' => 1]);
Text::get('key.files_uploaded', ':count files uploaded.', ['count' => 5]);

// Using helper with replacements
text('key.hello_user', 'Hello, :name!', ['name' => 'Edu']);
text('key.order_status', 'Your order #:order_id is :status.', [
    'order_id' => '12345',
    'status' => 'processing',
]);

Text::get('key.placeholder_escaped', 'This is a placeholder: \':name\' that should not replace.', ['name' => 'Edu']);
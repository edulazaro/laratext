@text('key.simple', 'Simple value')
@text('key.single.inside', "Value with 'single' quotes inside")
@text('key.double.inside', 'Value with "double" quotes inside')
@text('key.escaped.double', "Value with escaped \"double\" quotes")

Text::get('key.simple.php', 'Simple PHP value');
Text::get("key.single.inside.php", "PHP with 'single' quotes inside");
Text::get('key.double.inside.php', 'PHP with "double" quotes inside');
Text::get('key.escaped.single.php', 'PHP with escaped \'single\' quotes');

text('key.helper', 'Helper function call');
text('key.nospace','No space in call');

@text('key.blade.welcome_user', 'Welcome, :name!')
@text('key.blade.items_in_cart', 'You have :count items in your cart, :name.')
@text('key.blade.file_uploaded', ':count file uploaded.')
@text('key.blade.files_uploaded', ':count files uploaded.')

@text('key.blade.order_status', 'Your order #:order_id is :status.')

@text('key.blade.placeholder_escaped', "This is a placeholder: ':name' that should not replace.")

<?php

use EduLazaro\Laratext\Text;

Text::get('key.simple.php', 'Simple PHP value');
Text::get("key.single.inside.php", "PHP with 'single' quotes inside");
Text::get('key.double.inside.php', 'PHP with "double" quotes inside');
Text::get('key.escaped.single.php', 'PHP with escaped \'single\' quotes');
text("key.helper", 'Helper function call');
text('key.nospace','No space in call');

<?php

$EM_CONF['cal'] = [
    'title' => 'Calendar Base',
    'description' => 'A calendar combining all the functions of the existing calendar extensions plus adding some new features. It is based on the ical standard',
    'category' => 'plugin',
    'version' => '2.0.0',
    'state' => 'stable',
    'createDirs' => 'uploads/tx_cal/pics,uploads/tx_cal/ics,uploads/tx_cal/media',
    'author' => 'Jan Helke, Mario Matzulla',
    'author_email' => 'cal@typo3.helke.de, mario@matzullas.de',
    'constraints' => [
        'depends' => [
            'typo3' => '8.7.99 - 9.5.99'
        ],
        'suggests' => []
    ]
];

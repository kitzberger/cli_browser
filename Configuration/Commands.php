<?php

return [
    'database:browse-contents' => [
        'class' => \Kitzberger\CliBrowser\Command\CeBrowserCommand::class
    ],
    'database:browse-records' => [
        'class' => \Kitzberger\CliBrowser\Command\RecordBrowserCommand::class
    ],
];

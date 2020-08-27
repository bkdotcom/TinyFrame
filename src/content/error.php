<?php
switch ($this->exception->getCode()) {
    case '404':
        echo '<h2>This is not the page you\'re looking for</h2>';
        break;
    case '500':
    default:
        echo 'This is embarassing&hellip;';
}

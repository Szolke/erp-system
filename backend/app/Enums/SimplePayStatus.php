<?php

namespace App\Enums;

enum SimplePayStatus: string
{
    case Started = 'started';
    case InProgress = 'in_progress';
    case Success = 'success';
    case Fail = 'fail';
    case Timeout = 'timeout';
    case Cancel = 'cancel';
}

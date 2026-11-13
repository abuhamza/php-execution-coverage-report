<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * What static analysis says about one file as a whole.
 */
enum StaticVerdict: string
{
    /** Every method, class constant and enum case the file declares is reported dead. */
    case Unused = 'unused';
    /** At least one of them is not reported dead. */
    case Used = 'used';
    /** The file declares none, so the detector has nothing to say about it: functions, configuration, scripts. */
    case None = 'none';
}

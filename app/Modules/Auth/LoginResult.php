<?php

namespace Modules\Auth;

/**
 * How a login attempt ended.
 *
 * Three outcomes rather than a boolean, because "wrong password" and "too many
 * tries, wait" need different answers - and a bool would make the caller ask a
 * second question to find out which one it got.
 */
enum LoginResult {
    case Success;
    case Failed;
    case Throttled;
}

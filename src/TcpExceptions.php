<?php
declare(strict_types=1);

namespace KoutenDB;

require_once __DIR__ . '/KoutenDB.php';

class ConnectionException extends KoutenDBException {}
class ConnectionTimeoutException extends ConnectionException {}
class AuthenticationException extends KoutenDBException {}
class ProtocolException extends KoutenDBException {}
class VersionMismatchException extends ProtocolException {}
class ServerException extends KoutenDBException {}
class IndeterminateWriteException extends KoutenDBException {}

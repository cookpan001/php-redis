<?php

/**
 * Redis protocol specification
 * http://redis.io/topics/protocol
 * 
 * Redis database connection class
 * inspired by https://github.com/sash/php-redis
 * 
 * @author cookpan001
 * @version 1.0
 */
class Redis
{
    const TERMINATOR = "\r\n";
    private $retry = 10;
    private $port;
    private $host;
    private $conn;
    private $timeout = 5;
    public $debug = false;

    function __construct($host = 'localhost', $port = 6379)
    {
        $this->host = $host;
        $this->port = $port;
    }

    private function connect()
    {
        if ($this->conn)
        {
            return true;
        }
        $errno = 0;
        $errstr = '';
        $i = 0;
        while($i < $this->retry)
        {
            $this->conn = fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
            if ($this->conn)
            {
                return $this->conn;
            }
        }
        if (!$this->conn)
        {
            return false;
        }
        return true;
    }

    private function read()
    {
        if(!$this->conn)
        {
            return false;
        }
        $s = fgets($this->conn);
        return $s;
    }

    private function cmdResponse()
    {
        // Read the response
        $s = $this->read();
        if(false === $s)
        {
            return false;
        }
        switch ($s[0])
        {
            case '-' : // Error message
                return substr($s, 1);
            case '+' : // Single line response
                return substr($s, 1);
            case ':' : //Integer number
                return (int)substr($s, 1);
            case '$' : //Bulk data response
                $i = (int)(substr($s, 1));
                if ($i == - 1)
                {
                    return null;
                }
                $buffer = '';
                if ($i == 0)
                {
                    $s = $this->read();
                }
                while ($i > 0)
                {
                    $s = $this->read();
                    $l = strlen($s);
                    $i -= $l;
                    if ($i < 0)
                    {
                        $s = substr($s, 0, $i);
                    }
                    $buffer .= $s;
                }
                return $buffer;
            case '*' : // Multi-bulk data (a list of values)
                $i = (int) (substr($s, 1));
                if ($i == - 1)
                {
                    return null;
                }
                $res = array();
                for ($c = 0; $c < $i; $c ++)
                {
                    $res [] = $this->cmdResponse();
                }
                return $res;
            default :
                return false;
        }
    }

    private $pipeline = false;
    private $pipeline_commands = 0;

    function pipeline_begin()
    {
        $this->pipeline = true;
        $this->pipeline_commands = 0;
    }

    function pipeline_responses()
    {
        $response = array();
        for ($i = 0; $i < $this->pipeline_commands; $i++)
        {
            $response[] = $this->cmdResponse();
        }
        $this->pipeline = false;
        return $response;
    }

    private function cmd($command)
    {
        $this->connect();
        if (is_array($command))
        {
            // Use unified command format
            $s = '*' . count($command) . self::TERMINATOR;
            foreach ($command as $m)
            {
                $s.='$' . strlen($m) . self::TERMINATOR;
                $s.=$m . self::TERMINATOR;
            }
        }
        else
        {
            $s = $command . self::TERMINATOR;
        }
        while ($s)
        {
            $i = fwrite($this->conn, $s);
            if ($i == 0)
            {
                break;
            }
            $s = substr($s, $i);
        }
        if ($this->pipeline)
        {
            $this->pipeline_commands++;
            return null;
        }
        else
        {
            return $this->cmdResponse();
        }
    }

    function close()
    {
        if ($this->conn)
        {
            fclose($this->conn);
        }
        $this->conn = null;
    }

    function quit()
    {
        return $this->cmd('QUIT');
    }

    function __call($name, $params)
    {
        array_unshift($params, strtoupper($name));
        $data = $this->cmd($params);
        return $data;
    }

}

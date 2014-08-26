<?php
/**
 * Licensed to CRATE Technology GmbH("Crate") under one or more contributor
 * license agreements.  See the NOTICE file distributed with this work for
 * additional information regarding copyright ownership.  Crate licenses
 * this file to you under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.  You may
 * obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.  See the
 * License for the specific language governing permissions and limitations
 * under the License.
 *
 * However, if you have executed another commercial license agreement
 * with Crate these terms will supersede the license and you may use the
 * software solely pursuant to the terms of the relevant commercial agreement.
 */

namespace Crate\PDO;

use Crate\Stdlib\ArrayUtils;
use PDO as BasePDO;

class PDO extends BasePDO implements PDOInterface
{
    const VERSION = '1.0.0-dev';
    const DRIVER_NAME = 'crate';

    /**
     * @var array
     */
    private $attributes = [
        'defaultFetchMode' => self::FETCH_BOTH,
        'errorMode'        => self::ERRMODE_SILENT,
        'statementClass'   => 'Crate\PDO\PDOStatement',
        'timeout'          => 5
    ];

    /**
     * @var ArtaxExt\ClientInterface
     */
    private $client;

    /**
     * @var PDOStatement|null
     */
    private $lastStatement;

    /**
     * @var callable
     */
    private $request;

    /**
     * {@inheritDoc}
     *
     * @param string     $dsn      The HTTP endpoint to call
     * @param null       $username Unused
     * @param null       $passwd   Unused
     * @param null|array $options  Attributes to set on the PDO
     */
    public function __construct($dsn, $username, $passwd, $options)
    {
        foreach (ArrayUtils::toArray($options) as $attribute => $value) {
            $this->setAttribute($attribute, $value);
        }

        // Store the DSN for later
        $this->client = new ArtaxExt\Client($dsn, [
            'connectTimeout' => $this->attributes
        ]);

        // Define a callback that will be used in the PDOStatements
        // This way we don't expose this api to the end users.
        $this->request = function (PDOStatement $statement, $sql, array $parameters) {

            $this->lastStatement = $statement;

            try {

                return $this->client->execute($sql, $parameters);

            } catch (Exception\RuntimeException $e) {

                if ($this->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION) {
                    throw new Exception\PDOException($e->getMessage(), $e->getCode());
                }

                if ($this->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_WARNING) {
                    trigger_error(sprintf('[%d] %s', $e->getCode(), $e->getMessage()), E_USER_WARNING);
                }

                // should probably wrap this in a error object ?
                return [
                    'code'    => $e->getCode(),
                    'message' => $e->getMessage()
                ];
            }
        };
    }

    /**
     * {@inheritDoc}
     */
    public function prepare($statement, $options = null)
    {
        $options = ArrayUtils::toArray($options);

        if (isset($options[PDO::ATTR_CURSOR])) {
            throw new Exception\UnsupportedException('Driver does not support cursors');
        }

        return new PDOStatement($this, $this->request, $statement, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function beginTransaction()
    {
        throw new Exception\UnsupportedException;
    }

    /**
     * {@inheritDoc}
     */
    public function commit()
    {
        throw new Exception\UnsupportedException;
    }

    /**
     * {@inheritDoc}
     */
    public function rollBack()
    {
        throw new Exception\UnsupportedException;
    }

    /**
     * {@inheritDoc}
     */
    public function inTransaction()
    {
        throw new Exception\UnsupportedException;
    }

    /**
     * {@inheritDoc}
     */
    public function exec($statement)
    {
        $statement = $this->prepare($statement);
        $result    = $statement->execute();

        return $result === false ? false : $statement->rowCount();
    }

    /**
     * {@inheritDoc}
     */
    public function query($statement)
    {
        $statement = $this->prepare($statement);
        $result    = $statement->execute();

        return $result === false ? false : $statement;
    }

    /**
     * {@inheritDoc}
     */
    public function lastInsertId($name = null)
    {
        throw new Exception\UnsupportedException;
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode()
    {
        return $this->lastStatement === null ? null : $this->lastStatement->errorCode();
    }

    /**
     * {@inheritDoc}
     */
    public function errorInfo()
    {
        return $this->lastStatement === null ? null : $this->lastStatement->errorInfo();
    }

    /**
     * {@inheritDoc}
     */
    public function setAttribute($attribute, $value)
    {
        switch ($attribute) {
            case self::ATTR_DEFAULT_FETCH_MODE:
                $this->attributes['defaultFetchMode'] = $value;
                break;

            case self::ATTR_ERRMODE:
                $this->attributes['errorMode'] = $value;
                break;

            case self::ATTR_TIMEOUT:
                $this->attributes['timeout'] = (int)$value;
                $this->client->setTimeout((int)$value);
                break;

            default:
                throw new Exception\PDOException('Unsupported driver attribute');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getAttribute($attribute)
    {
        switch ($attribute) {
            case PDO::ATTR_PERSISTENT:
                return false;

            case PDO::ATTR_PREFETCH:
                return false;

            case PDO::ATTR_CLIENT_VERSION:
                return self::VERSION;

            case PDO::ATTR_SERVER_VERSION:
                return $this->client->getServerVersion();

            case PDO::ATTR_SERVER_INFO:
                return $this->client->getServerInfo();

            case PDO::ATTR_TIMEOUT:
                return $this->attributes['timeout'];

            case PDO::ATTR_DEFAULT_FETCH_MODE:
                return $this->attributes['defaultFetchMode'];

            case PDO::ATTR_ERRMODE:
                return $this->attributes['errorMode'];

            case PDO::ATTR_DRIVER_NAME:
                return static::DRIVER_NAME;

            case PDO::ATTR_STATEMENT_CLASS:
                return [$this->attributes['statementClass']];

            default:
                // PHP Switch a lose comparison
                if ($attribute === PDO::ATTR_AUTOCOMMIT) {
                    return true;
                }

                throw new Exception\PDOException('Unsupported driver attribute');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function quote($string, $parameter_type = PDO::PARAM_STR)
    {
        switch ($parameter_type) {
            case PDO::PARAM_INT:
                return (int)$string;

            case PDO::PARAM_BOOL:
                return (bool)$string;

            case PDO::PARAM_NULL:
                return null;

            case PDO::PARAM_LOB:
                throw new Exception\UnsupportedException('This is not supported by crate.io');

            case PDO::PARAM_STR:
                throw new Exception\UnsupportedException('This is not supported, please use prepared statements.');

            default:
                throw new Exception\InvalidArgumentException('Unknown param type');
        }
    }

    /**
     * {@inheritDoc}
     */
    public static function getAvailableDrivers()
    {
        return array_merge(parent::getAvailableDrivers(), [static::DRIVER_NAME]);
    }
}
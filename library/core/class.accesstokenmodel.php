<?php
/**
 * @author Todd Burry <todd@vanillaforums.com>
 * @copyright 2009-2019 Vanilla Forums Inc.
 * @license GPL-2.0-only
 */

use Vanilla\Contracts\ConfigurationInterface;
use Vanilla\CurrentTimeStamp;
use Vanilla\Formatting\DateTimeFormatter;
use Webmozart\Assert\Assert;

/**
 * Handles access tokens.
 *
 * When using this model you should be using the {@link AccessTokenModel::issue()} and {@link AccessTokenModel::verify()}
 * methods most of the time.
 */
class AccessTokenModel extends Gdn_Model
{
    use \Vanilla\PrunableTrait;
    use \Vanilla\TokenSigningTrait;

    const TYPE_SYSTEM = "system-access";
    const CONFIG_SYSTEM_TOKEN = "APIv2.SystemAccessToken";
    const CONFIG_SYSTEM_TOKEN_ROTATION_CRON = "APIv2.RotationCronFrequency";

    /** @var ConfigurationInterface */
    private $config;

    /** @var int */
    private $version;

    /**
     * Construct an {@link AccessToken} object.
     *
     * @param string $secret The secret used to sign access tokens for the client.
     */
    public function __construct($secret = "")
    {
        parent::__construct("AccessToken");
        $this->PrimaryKey = "AccessTokenID";
        $secret = $secret ?: c("Garden.Cookie.Salt");
        $this->version = Gdn::config()->configKeyExists("Garden.Cookie.OldSalt") ? 2 : 1;
        $this->setSecret($secret);
        $this->tokenIdentifier = "access token";
        $this->setPruneAfter("1 day")->setPruneField("DateExpires");
        $this->config = \Gdn::getContainer()->get(ConfigurationInterface::class);
    }

    /**
     * Ensure there is one single system-access token in the configuration.
     * This is meant to be run frequently in order to have effective use.
     */
    public function ensureSingleSystemToken(): void
    {
        $systemUserID = $this->config->get("Garden.SystemUserID", null);

        // Definitely shouldn't happen.
        // Ensured to exist in the dashboard structure.
        Assert::integerish($systemUserID);

        // Get our current token.
        $currentFullToken = $this->config->get(self::CONFIG_SYSTEM_TOKEN, null);
        $currentTokenID = null;
        if ($currentFullToken !== null) {
            $currentTokenRow = $this->getToken($this->trim($currentFullToken));
            if ($currentTokenRow !== false) {
                $currentTokenID = $currentTokenRow["AccessTokenID"];
            }
        }

        // Expire all existing system tokens
        $db = $this->SQL->Database;
        $db->beginTransaction();

        try {
            // Expire all existing tokens
            $this->update(
                [
                    "DateExpires" => CurrentTimeStamp::getDateTime()
                        ->modify("-1 hour")
                        ->format(MYSQL_DATE_FORMAT),
                ],
                [
                    "UserID" => $systemUserID,
                    "Type" => self::TYPE_SYSTEM,
                ]
            );

            // Rewrite the current token if we have one with a little bit of buffer time.
            if ($currentTokenID !== null) {
                $this->setField($currentTokenID, [
                    "DateExpires" => CurrentTimeStamp::getDateTime()
                        ->modify("+6 hours")
                        ->format(MYSQL_DATE_FORMAT),
                ]);
            }

            // Now create a new token.
            // Issue a new token.
            $newToken = $this->issue(
                $systemUserID,
                "1 month", // Long expiration, but get's revoked frequently.
                self::TYPE_SYSTEM
            );

            $db->commitTransaction();
            // Save the new token into the config for access by orch or for system recovery.
            $this->config->saveToConfig(self::CONFIG_SYSTEM_TOKEN, $newToken, ["BypassLogging" => true]);
        } catch (Throwable $e) {
            $db->rollbackTransaction();
            throw $e;
        }
    }

    /**
     * Issue an access token.
     *
     * @param int $userID The user ID the token is issued to.
     * @param mixed $expires The date the token expires. This can be a string relative date.
     * @param string $type The type of token. Pass a string that you define here. This will usually be the name of an addon.
     * @param array $scope The permission scope of the token. Leave blank to inherit the user's permissions.
     * @return string Returns a signed access token.
     */
    public function issue($userID, $expires = "1 month", $type = "system", $scope = [])
    {
        if ($expires instanceof DateTimeInterface) {
            $expireDate = $expires->format(MYSQL_DATE_FORMAT);
        } else {
            $expireDate = Gdn_Format::toDateTime($this->toTimestamp($expires));
        }
        $token = $this->insert([
            "UserID" => $userID,
            "Type" => $type,
            "DateExpires" => $expireDate,
            "Scope" => $scope,
            "Attributes" => [
                "version" => $this->version,
            ],
        ]);

        if (!$token) {
            throw new Gdn_UserException($this->Validation->resultsText(), 400);
        }

        $accessToken = $this->signToken($token, $expireDate);
        return $accessToken;
    }

    /**
     * Revoke an already issued token.
     *
     * @param string|int $token The token, access or numeric ID token to revoke.
     * @return bool Returns true if the token was revoked or false otherwise.
     */
    public function revoke($token)
    {
        $id = false;
        if (filter_var($token, FILTER_VALIDATE_INT)) {
            $id = $token;
            $row = $this->getID($id, DATASET_TYPE_ARRAY);
        } else {
            $token = $this->trim($token);
            $row = $this->getToken($token);
            if ($row) {
                $id = $row["AccessTokenID"];
            }
        }
        if ($row !== false) {
            if (isset($row["Attributes"])) {
                $attributes = $row["Attributes"];
            } else {
                $attributes = [];
            }
            $attributes["revoked"] = true;

            $this->setField($id, [
                "DateExpires" => Gdn_Format::toDateTime(strtotime("-1 hour")),
                "Attributes" => $attributes,
            ]);
        }

        return $this->Database->LastInfo["RowCount"] > 0;
    }

    /**
     * Get an access token by its numeric ID.
     *
     * @param int $id
     * @param string $datasetType
     * @param array $options
     * @return array|bool
     */
    public function getID($id, $datasetType = DATASET_TYPE_ARRAY, $options = [])
    {
        $row = $this->getWhere(["AccessTokenID" => $id])->firstRow($datasetType);
        return $row;
    }

    /**
     * Fetch an access token row using the token.
     *
     * @param mixed $token
     * @return array|bool
     */
    public function getToken($token)
    {
        $row = $this->getWhere(["Token" => $token])->firstRow(DATASET_TYPE_ARRAY);
        return $row;
    }

    /**
     * {@inheritdoc}
     */
    public function insert($fields)
    {
        if (empty($fields["Token"])) {
            $fields["Token"] = $this->randomToken();
        }

        $this->encodeRow($fields);
        parent::insert($fields);
        if (!empty($this->Database->LastInfo["RowCount"])) {
            $this->prune();
            $result = $fields["Token"];
        } else {
            $result = false;
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function update($fields, $where = false, $limit = false)
    {
        $this->encodeRow($fields);
        return parent::update($fields, $where, $limit);
    }

    /**
     * {@inheritdoc}
     */
    public function setField($rowID, $property, $value = false)
    {
        if (!is_array($property)) {
            $property = [$property => $value];
        }
        $this->encodeRow($property);
        parent::setField($rowID, $property);
    }

    /**
     * Sign a token row.
     *
     * @param array $row The database row of the token.
     * @return string Returns a signed token.
     */
    public function signTokenRow($row)
    {
        $token = val("Token", $row);
        $expires = val("DateExpires", $row);

        if (($row["Attributes"]["version"] ?? 1) === 1 && Gdn::config()->configKeyExists("Garden.Cookie.OldSalt")) {
            // Backup current secret and use old cookie salt for signature verification
            $originalSecret = $this->secret;
            $this->setSecret(Gdn::config()->get("Garden.Cookie.OldSalt"));
        }

        $codedToken = $this->signToken($token, $expires);

        if (isset($originalSecret)) {
            // Restore original secret in case we need to issue new tokens
            $this->setSecret($originalSecret);
        }

        return $codedToken;
    }

    /**
     * Verify an access token.
     *
     * @param string $accessToken An access token issued from {@link AccessTokenModel::issue()}.
     * @param bool $throw Whether or not to throw an exception on a verification error.
     * @return array|false Returns the valid access token row or **false**.
     * @throws \Exception Throws an exception if the token is invalid and {@link $throw} is **true**.
     */
    public function verify($accessToken, $throw = false)
    {
        $token = $this->trim($accessToken);

        // Need to get token first to check version
        $row = $this->getToken($token);

        if (($row["Attributes"]["version"] ?? 1) === 1 && Gdn::config()->configKeyExists("Garden.Cookie.OldSalt")) {
            // Backup current secret and use old cookie salt for signature verification
            $originalSecret = $this->secret;
            $this->setSecret(Gdn::config()->get("Garden.Cookie.OldSalt"));
        }

        if (!$this->verifyTokenSignature($accessToken, $throw)) {
            return false;
        }

        if (isset($originalSecret)) {
            // Restore original secret in case we need to issue new tokens
            $this->setSecret($originalSecret);
        }

        if (!$row) {
            return $this->tokenError("Access token not found.", 401, $throw);
        }

        if (!empty($row["Attributes"]["revoked"])) {
            return $this->tokenError("Your access token was revoked.", 401, $throw);
        }

        // Check the expiry date from the database.
        $dbExpires = $this->toTimestamp($row["DateExpires"]);
        if ($dbExpires === 0) {
        } elseif ($dbExpires < CurrentTimeStamp::get()) {
            return $this->tokenError("Your access token has expired.", 401, $throw);
        }

        // Update the DateLastUsed if the delta is greater than 5 minutes.
        $dateLastUsed = $this->toTimestamp($row["DateLastUsed"]) ?? 0;
        if ($dateLastUsed + 5 * 60 < CurrentTimeStamp::get()) {
            $this->setField($row["AccessTokenID"], ["DateLastUsed" => DateTimeFormatter::getCurrentDateTime()]);
        }

        return $row;
    }

    /**
     * {@inheritdoc}
     */
    public function getWhere(
        $where = false,
        $orderFields = "",
        $orderDirection = "asc",
        $limit = false,
        $offset = false
    ) {
        $result = parent::getWhere($where, $orderFields, $orderDirection, $limit, $offset);
        array_walk($result->result(), [$this, "decodeRow"]);

        return $result;
    }

    /**
     * Save an attribute on an access token row.
     *
     * @param int $accessTokenID
     * @param string $key
     * @param mixed $value
     * @return array|bool
     */
    public function setAttribute($accessTokenID, $key, $value)
    {
        $row = $this->getID($accessTokenID, DATASET_TYPE_ARRAY);
        $result = false;
        if ($row) {
            $attributes = array_key_exists("Attributes", $row) ? $row["Attributes"] : [];
            $attributes[$key] = $value;
            $this->update(["Attributes" => $attributes], ["AccessTokenID" => $accessTokenID], 1);
            $result = $this->getID($accessTokenID);
        }
        return $result;
    }

    /**
     * Serialize a token entry for direct insertion to the database.
     *
     * @param array &$row The row to encode.
     */
    protected function encodeRow(&$row)
    {
        if (is_object($row) && !$row instanceof ArrayAccess) {
            $row = (array) $row;
        }

        foreach (["Scope", "Attributes"] as $field) {
            if (isset($row[$field]) && is_array($row[$field])) {
                $row[$field] = empty($row[$field]) ? null : json_encode($row[$field], JSON_UNESCAPED_SLASHES);
            }
        }
    }

    /**
     * Unserialize a row from the database for API consumption.
     *
     * @param array &$row The row to decode.
     */
    protected function decodeRow(&$row)
    {
        $isObject = false;
        if (is_object($row) && !$row instanceof ArrayAccess) {
            $isObject = true;
            $row = (array) $row;
        }

        $row["InsertIPAddress"] = ipDecode($row["InsertIPAddress"]);

        foreach (["Scope", "Attributes"] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $row[$field] = json_decode($row[$field], true);
            }
        }

        if ($isObject) {
            $row = (object) $row;
        }
    }

    /**
     * Trim the expiry date and signature off of a token.
     *
     * @param string $accessToken The access token to trim.
     */
    public function trim($accessToken)
    {
        if (strpos($accessToken, ".") !== false) {
            [$_, $token] = explode(".", $accessToken);
            return $token;
        }
        return $accessToken;
    }

    /**
     * Generate and sign a token.
     *
     * @param string $expires When the token expires.
     * @return string
     */
    public function randomSignedToken($expires = "2 months")
    {
        return $this->signToken($this->randomToken(), $expires);
    }

    /**
     * @return int
     */
    public function getVersion(): int
    {
        return $this->version;
    }
}

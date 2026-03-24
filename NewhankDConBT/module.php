<?php

declare(strict_types=1);

/**
 * Newhank DConBT – Bluetooth-to-Dante Adapter
 *
 * Standalone Device Module (Type 3) – Direct UDP communication.
 * Protocol: ASCII commands via UDP Port 1119
 * Syntax:   <Operator> <Space> <Command> [<Space> <Parameter>]\r
 * Response: ST DN<number> <CMD> <value>\r
 *
 * @author  FACE GmbH
 * @version 1.0
 * @see     https://newhank.com/product/dconbt/
 * @see     Manual: https://newhank.com/wp-content/uploads/2025/01/Manual_DConBT.pdf
 */
class NewhankDConBT extends IPSModule
{
    // ─── UDP Protocol Constants ─────────────────────────────────────
    private const UDP_PORT    = 1119;
    private const UDP_TIMEOUT = 2; // seconds
    private const TERMINATOR  = "\r";

    // ─── Operators (from manual) ─────────────────────────────────────
    private const OP_SET = 'SET';
    private const OP_GET = 'GET';

    // ─── Commands (from manual, page 5 "Third-party API") ───────────
    private const CMD_DEVICE_NUM    = 'DN';   // Device number (0-999)
    private const CMD_PAIR_MODE     = 'PM';   // Pairing mode (0=off, 1=on)
    private const CMD_CONNECTION    = 'CO';   // Host connection status (0/1)
    private const CMD_CLIENT_NAME   = 'PN';   // Paired client name
    private const CMD_CALL_STATUS   = 'CA';   // Call status (0=Idle,1=Incoming,2=Calling,3=Outgoing)
    private const CMD_STREAM_STATUS = 'SS';   // Streaming status (0/1)
    private const CMD_AUTOCONNECT   = 'CM';   // Auto connect disable (0=off, 1=on)
    private const CMD_PIN_ENABLE    = 'PE';   // PIN code enable (0=off, 1=on)
    private const CMD_PIN_CODE      = 'PC';   // PIN code (0000-9999)
    private const CMD_PAIR_BUTTON   = 'PB';   // Pair button enable (0=off, 1=on)
    private const CMD_LED           = 'LM';   // LED enable (0=off, 1=on)
    private const CMD_BT_SSID       = 'BS';   // Bluetooth SSID / radio name
    private const CMD_HIDE_RADIO    = 'BH';   // Hide radio enable (0=off, 1=on)
    private const CMD_ALL           = 'ALL';  // All status (returns multiple responses)
    private const CMD_ANSWER_CALL   = 'AC';   // Answer call
    private const CMD_REJECT_CALL   = 'RC';   // Reject / hangup call
    private const CMD_VOL_UP        = 'VUP';  // Volume up
    private const CMD_VOL_DOWN      = 'VDN';  // Volume down
    private const CMD_PLAY          = 'PLY';  // Play
    private const CMD_PAUSE         = 'PZ';   // Pause / stop
    private const CMD_PREV          = 'PRV';  // Previous track
    private const CMD_NEXT          = 'NXT';  // Next track

    // ─── Module Status Codes ────────────────────────────────────────
    private const STATUS_OK         = 102;
    private const STATUS_INACTIVE   = 104;
    private const STATUS_NO_HOST    = 200;
    private const STATUS_COMM_ERROR = 201;

    // ═══════════════════════════════════════════════════════════════
    //  LIFECYCLE
    // ═══════════════════════════════════════════════════════════════

    public function Create()
    {
        parent::Create();

        // ── Properties ──────────────────────────────────────────
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', self::UDP_PORT);
        $this->RegisterPropertyInteger('PollInterval', 10); // seconds
        $this->RegisterPropertyInteger('DeviceNumber', 1);

        // ── Timer ───────────────────────────────────────────────
        $this->RegisterTimer('PollTimer', 0, 'NHDBT_Poll($_IPS["TARGET"]);');

        // ── Profiles ────────────────────────────────────────────
        $this->EnsureProfiles();

        // ── Variables ───────────────────────────────────────────
        // Transport Controls
        $this->RegisterVariableInteger('Transport', 'Transport', $this->GetProfileName('Transport'), 10);
        $this->EnableAction('Transport');

        $this->RegisterVariableInteger('Volume', 'Lautstärke', $this->GetProfileName('Volume'), 15);
        $this->EnableAction('Volume');

        // Connection & Streaming Status
        $this->RegisterVariableBoolean('AudioActive', 'Audio aktiv', '~Switch', 20);
        $this->RegisterVariableBoolean('ClientPaired', 'Client verbunden', '~Switch', 30);
        $this->RegisterVariableString('ClientName', 'Client-Name', '', 40);

        // Pairing
        $this->RegisterVariableBoolean('PairingMode', 'Pairing-Modus', '~Switch', 50);
        $this->EnableAction('PairingMode');

        // Settings
        $this->RegisterVariableString('BluetoothName', 'Bluetooth-Name', '', 60);
        $this->EnableAction('BluetoothName');

        $this->RegisterVariableBoolean('LEDEnabled', 'LED aktiv', '~Switch', 70);
        $this->EnableAction('LEDEnabled');

        $this->RegisterVariableBoolean('AutoConnect', 'Auto-Connect', '~Switch', 75);
        $this->EnableAction('AutoConnect');

        // Action button – disables pairing mode (no explicit disconnect command in API)
        $this->RegisterVariableInteger('Disconnect', 'Trennen', $this->GetProfileName('Action'), 80);
        $this->EnableAction('Disconnect');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $host = trim($this->ReadPropertyString('Host'));

        if ($host === '') {
            $this->SetStatus(self::STATUS_NO_HOST);
            $this->SetTimerInterval('PollTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('PollInterval');
        $this->SetTimerInterval('PollTimer', $interval * 1000);
        $this->SetStatus(self::STATUS_OK);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Poll();
        }
    }

    public function Destroy()
    {
        $prefix = 'NHDBT.' . $this->InstanceID . '.';
        foreach (IPS_GetVariableProfileList() as $p) {
            if (strpos($p, $prefix) === 0) {
                @IPS_DeleteVariableProfile($p);
            }
        }
        parent::Destroy();
    }

    // ═══════════════════════════════════════════════════════════════
    //  PUBLIC API (prefix functions)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Poll all device status at once using GET ALL.
     * The device responds with multiple ST lines.
     */
    public function Poll(): void
    {
        $this->SendDebug(__FUNCTION__, 'Polling device...', 0);

        $resp = $this->SendUDP(self::OP_GET, self::CMD_ALL);
        if ($resp === false) {
            $this->SetStatus(self::STATUS_COMM_ERROR);
            return;
        }

        // GET ALL returns multiple lines separated by \r
        foreach (explode("\r", $resp) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $this->ParseResponse($line);
            }
        }

        $this->SetStatus(self::STATUS_OK);
    }

    /**
     * Send a raw command string for testing/debugging.
     */
    public function SendRawCommand(string $Command): string
    {
        $resp = $this->SendRawUDP($Command . self::TERMINATOR);
        return ($resp !== false) ? $resp : 'ERROR: No response';
    }

    public function Play(): void
    {
        $this->SendUDP(self::OP_SET, self::CMD_PLAY);
    }

    public function Pause(): void
    {
        $this->SendUDP(self::OP_SET, self::CMD_PAUSE);
    }

    public function NextTrack(): void
    {
        $this->SendUDP(self::OP_SET, self::CMD_NEXT);
    }

    public function PreviousTrack(): void
    {
        $this->SendUDP(self::OP_SET, self::CMD_PREV);
    }

    /**
     * Disable pairing mode – closest to a software disconnect.
     * The DConBT API has no explicit "disconnect client" command.
     */
    public function DisconnectClient(): void
    {
        $this->SendUDP(self::OP_SET, self::CMD_PAIR_MODE, '0');
        $this->SetValueIfChanged('PairingMode', false);
    }

    public function SetPairingMode(bool $Active): void
    {
        $this->SendUDP(self::OP_SET, self::CMD_PAIR_MODE, $Active ? '1' : '0');
    }

    public function SetBluetoothName(string $Name): void
    {
        $this->SendUDP(self::OP_SET, self::CMD_BT_SSID, $Name);
    }

    public function SetLED(bool $Enabled): void
    {
        $this->SendUDP(self::OP_SET, self::CMD_LED, $Enabled ? '1' : '0');
    }

    public function SetAutoConnect(bool $Enabled): void
    {
        // CM controls "auto connect disable": CM 1 = disabled → AutoConnect OFF
        $this->SendUDP(self::OP_SET, self::CMD_AUTOCONNECT, $Enabled ? '0' : '1');
    }

    // ═══════════════════════════════════════════════════════════════
    //  REQUEST ACTION (UI interactions)
    // ═══════════════════════════════════════════════════════════════

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Transport':
                $this->HandleTransportAction((int)$Value);
                break;

            case 'Volume':
                if ((int)$Value === 0) {
                    $this->SendUDP(self::OP_SET, self::CMD_VOL_UP);
                } else {
                    $this->SendUDP(self::OP_SET, self::CMD_VOL_DOWN);
                }
                break;

            case 'PairingMode':
                $this->SetPairingMode((bool)$Value);
                $this->SetValueIfChanged('PairingMode', (bool)$Value);
                break;

            case 'BluetoothName':
                $this->SetBluetoothName((string)$Value);
                $this->SetValueIfChanged('BluetoothName', (string)$Value);
                break;

            case 'LEDEnabled':
                $this->SetLED((bool)$Value);
                $this->SetValueIfChanged('LEDEnabled', (bool)$Value);
                break;

            case 'AutoConnect':
                $this->SetAutoConnect((bool)$Value);
                $this->SetValueIfChanged('AutoConnect', (bool)$Value);
                break;

            case 'Disconnect':
                $this->DisconnectClient();
                break;

            default:
                $this->SendDebug(__FUNCTION__, 'Unknown ident: ' . $Ident, 0);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRIVATE: Transport handling
    // ═══════════════════════════════════════════════════════════════

    private function HandleTransportAction(int $Value): void
    {
        switch ($Value) {
            case 0: $this->Play();          break;
            case 1: $this->Pause();         break;
            case 2: $this->PreviousTrack(); break;
            case 3: $this->NextTrack();     break;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRIVATE: Response Parsing
    // ═══════════════════════════════════════════════════════════════

    /**
     * Parse a DConBT response string.
     *
     * Response format: ST DN<number> <CMD> <value>
     * Examples:
     *   ST DN1 CO 1        → connection status = connected
     *   ST DN1 PN iPhone   → paired client name = "iPhone"
     *   ST DN1 SS 0        → streaming status = inactive
     *   ST DN1 PM 1        → pairing mode = on
     *   ST DN1 LM 1        → LED = on
     *   ST DN3 BS DCon     → Bluetooth name = "DCon"
     *   ST DN14 PLY OK     → play command acknowledged
     */
    private function ParseResponse(string $response): void
    {
        $this->SendDebug('Parse', $response, 0);

        // Split into max 4 parts to keep multi-word values (e.g. client names) intact
        $parts = explode(' ', $response, 4);

        if (count($parts) < 3 || $parts[0] !== 'ST') {
            return;
        }

        $cmd   = (string)($parts[2] ?? '');
        $value = trim((string)($parts[3] ?? ''));

        switch ($cmd) {
            case 'SS': // Streaming status
                $this->SetValueIfChanged('AudioActive', $value === '1');
                break;

            case 'CO': // Connection status
                $this->SetValueIfChanged('ClientPaired', $value === '1');
                if ($value !== '1') {
                    $this->SetValueIfChanged('ClientName', '');
                }
                break;

            case 'PN': // Paired client name
                $this->SetValueIfChanged('ClientName', $value);
                break;

            case 'PM': // Pairing mode
                $this->SetValueIfChanged('PairingMode', $value === '1');
                break;

            case 'LM': // LED enable
                $this->SetValueIfChanged('LEDEnabled', $value === '1');
                break;

            case 'BS': // Bluetooth SSID
                $this->SetValueIfChanged('BluetoothName', $value);
                break;

            case 'CM': // Auto connect disable: 1 = disabled → AutoConnect OFF
                $this->SetValueIfChanged('AutoConnect', $value === '0');
                break;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRIVATE: UDP Communication
    // ═══════════════════════════════════════════════════════════════

    /**
     * Build and send a structured UDP command.
     *
     * @param string      $operator  'GET' or 'SET'
     * @param string      $command   Command keyword (e.g. 'PM', 'BS')
     * @param string|null $parameter Optional parameter value
     * @return string|false Response string or false on failure
     */
    private function SendUDP(string $operator, string $command, ?string $parameter = null)
    {
        $msg = $operator . ' ' . $command;
        if ($parameter !== null) {
            $msg .= ' ' . $parameter;
        }
        $msg .= self::TERMINATOR;

        $this->SendDebug('TX', $msg, 0);
        return $this->SendRawUDP($msg);
    }

    /**
     * Send raw bytes via UDP and read response.
     *
     * @param string $data Raw bytes to send (including terminator)
     * @return string|false
     */
    private function SendRawUDP(string $data)
    {
        $host = trim($this->ReadPropertyString('Host'));
        $port = $this->ReadPropertyInteger('Port');

        if ($host === '') {
            $this->SendDebug('UDP', 'No host configured', 0);
            return false;
        }

        $errno  = 0;
        $errstr = '';
        $fp     = @fsockopen('udp://' . $host, $port, $errno, $errstr, self::UDP_TIMEOUT);

        if (!is_resource($fp)) {
            $this->SendDebug('UDP', 'Connection failed: ' . $errstr . ' (' . $errno . ')', 0);
            $this->LogMessage('UDP connection to ' . $host . ':' . $port . ' failed: ' . $errstr, KL_ERROR);
            return false;
        }

        stream_set_timeout($fp, self::UDP_TIMEOUT);

        $written = @fwrite($fp, $data);
        if ($written === false || $written === 0) {
            $this->SendDebug('UDP', 'Write failed', 0);
            fclose($fp);
            return false;
        }

        $response = @fread($fp, 4096);
        fclose($fp);

        if ($response === false || $response === '') {
            $this->SendDebug('RX', '(no response)', 0);
            return false;
        }

        $response = trim($response);
        $this->SendDebug('RX', $response, 0);
        return $response;
    }

    // ═══════════════════════════════════════════════════════════════
    //  PRIVATE: Helpers
    // ═══════════════════════════════════════════════════════════════

    private function SetValueIfChanged(string $ident, $value): void
    {
        $vid = @$this->GetIDForIdent($ident);
        if ($vid === 0) {
            return;
        }
        $old = GetValue($vid);
        if (is_float($old) || is_float($value)) {
            if (round((float)$old, 3) === round((float)$value, 3)) {
                return;
            }
        } else {
            if ($old === $value) {
                return;
            }
        }
        $this->SetValue($ident, $value);
    }

    private function GetProfileName(string $suffix): string
    {
        return 'NHDBT.' . $this->InstanceID . '.' . $suffix;
    }

    private function EnsureProfiles(): void
    {
        $pTransport = $this->GetProfileName('Transport');
        if (!IPS_VariableProfileExists($pTransport)) {
            IPS_CreateVariableProfile($pTransport, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation($pTransport, 0, 'Play',   'HollowArrowRight',       0x00CC00);
            IPS_SetVariableProfileAssociation($pTransport, 1, 'Pause',  'Sleep',                  0xFFAA00);
            IPS_SetVariableProfileAssociation($pTransport, 2, 'Zurück', 'HollowDoubleArrowLeft',  -1);
            IPS_SetVariableProfileAssociation($pTransport, 3, 'Weiter', 'HollowDoubleArrowRight', -1);
            IPS_SetVariableProfileIcon($pTransport, 'Speaker');
        }

        $pVolume = $this->GetProfileName('Volume');
        if (!IPS_VariableProfileExists($pVolume)) {
            IPS_CreateVariableProfile($pVolume, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation($pVolume, 0, 'Lauter',  'HollowArrowUp',   -1);
            IPS_SetVariableProfileAssociation($pVolume, 1, 'Leiser',  'HollowArrowDown', -1);
            IPS_SetVariableProfileIcon($pVolume, 'Speaker');
        }

        $pAction = $this->GetProfileName('Action');
        if (!IPS_VariableProfileExists($pAction)) {
            IPS_CreateVariableProfile($pAction, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation($pAction, 0, 'Trennen', 'Cross', 0xFF0000);
            IPS_SetVariableProfileIcon($pAction, 'Network');
        }
    }
}

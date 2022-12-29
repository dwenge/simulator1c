<?php
declare(strict_types=1);

namespace SimulatorImport;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ServerException;
use Psr\Http\Message\ResponseInterface;

class Application
{
    const STATUS_FAIL = 0;
    const STATUS_SUCCESS = 1;
    const STATUS_PROGRESS = 2;

    private Client $client;
    private string $uri;
    private string $type;
    private ?string $sessidName = null;
    private ?string $sessidVal = null;
    private ?string $serverTime = null;

    private string $login;
    private string $password;
    private ?CookieJar $cookie = null;

    private $useZip = false;
    private $fileSizeTransport = 512000;

    /** @var string[] */
    private array $filenames;

    private bool $showMessage = true;

    public function __construct(string $uri, string $type, string $login, string $password, array $filenames)
    {
        $this->client = new Client([
            'headers' => [
                'user-agent' => '1C+Enterprise/8.21',
            ],
        ]);
        $this->uri = $uri;
        $this->type = $type;
        $this->login = $login;
        $this->password = $password;
        $this->filenames = $filenames;

        // check file
        foreach ($this->filenames as $fl) {
            if (!file_exists($fl)) {
                throw new \Exception("Файл \"{$fl}\" не существует");
            }
        }
    }

    public function disableOutputMessages(bool $off = true): self
    {
        $this->showMessage = !$off;
        return $this;
    }

    private function consoleMessage($mode, $resp, $convertEncoding = true)
    {
        if (!$this->showMessage) return;

        if ($convertEncoding) {
            $resp = mb_convert_encoding(join(PHP_EOL, $resp), 'UTF-8', 'Windows-1251');
        }
        echo join(PHP_EOL, [
                date('H:i:s'),
                "Mode=$mode",
                $resp,
                "---",
            ]) . PHP_EOL;
    }

    private function parseResponse(ResponseInterface $response)
    {
        $body = $response->getBody()->getContents();
        return explode("\n", $body);
    }

    private function getParam($respLine, $separator = '=')
    {
        [, $r] = explode($separator, $respLine);
        return $r;
    }

    private function getStatus($resp): int
    {
        switch ($resp[0]) {
            case 'success':
                return static::STATUS_SUCCESS;
            case 'progress':
                return static::STATUS_PROGRESS;
        }
        return static::STATUS_FAIL;
    }

    private function getMessage($resp)
    {
        return \mb_convert_encoding($resp[1], 'UTF-8', 'Windows-1251');
    }

    private function send(string $mode, array $params = [], $method = 'POST')
    {
        $params['query']['type'] = $this->type;
        $params['query']['mode'] = $mode;
        if ($this->sessidName && $this->sessidVal) {
            $params['query'][$this->sessidName] = $this->sessidVal;
        }
        if ($this->cookie) {
            $params['cookies'] = $this->cookie;
        }
        try {
            $resp = $this->client->request($method, $this->uri, $params);
        } catch (ServerException $e) {
            $this->consoleMessage($mode, $e->getResponse()->getBody()->getContents(), false);
            throw $e;
        }
        $resp = $this->parseResponse($resp);
        $this->consoleMessage($mode, $resp);
        return $resp;
    }

    private function auth()
    {
        $resp = $this->send('checkauth', ['auth' => [$this->login, $this->password]]);
        if ($this->getStatus($resp) !== static::STATUS_SUCCESS) {
            throw new \Exception($this->getMessage($resp));
        }

        $this->cookie = CookieJar::fromArray([$resp[1] => $resp[2]], parse_url($this->uri)['host']);
        [$this->sessidName, $this->sessidVal] = explode('=', $resp[3]);
        $this->serverTime = $this->getParam($resp[4]);
    }

    private function init()
    {
        $resp = $this->send('init');
        $this->useZip = $this->getParam($resp[0]) === 'yes';
        $this->fileSizeTransport = (int)$this->getParam($resp[1]) ?: 512000;
    }

    private function uploadSingleFile($filename, ?string $basefilename = null)
    {
        if (is_null($basefilename)) {
            $basefilename = basename($filename);
        }

        $f = fopen($filename, 'rb');
        if (!$f) {
            throw new \Exception("Не удалось открыть файл \"{$filename}\"");
        }
        while (!feof($f)) {
            $resp = $this->send('file', [
                'query' => ['filename' => $basefilename],
                'body'  => fread($f, $this->fileSizeTransport),
            ]);

            if ($this->getStatus($resp) !== self::STATUS_SUCCESS) {
                throw new \Exception($this->getMessage($resp));
            }
        }
        fclose($f);
    }

    private function upload()
    {
        if ($this->useZip) {
            $pathfile = tempnam('/tmp', 'sim_import_');
            $filename = basename($pathfile) . '.zip';
            $zip = new \ZipArchive();
            $zip->open($pathfile);
            foreach ($this->filenames as $fl) {
                $zip->addFile($fl, basename($fl));
            }
            $zip->close();
            $this->uploadSingleFile($pathfile, $filename);
        }
        else {
            foreach ($this->filenames as $fl) {
                $this->uploadSingleFile($fl);
            }
        }
    }

    private function import()
    {
        foreach ($this->filenames as $fl) {
            $filename = basename($fl);
            do {
                $resp = $this->send('import', ['query' => compact('filename')]);
            } while ($this->getStatus($resp) === self::STATUS_PROGRESS);
            if ($this->getStatus($resp) !== self::STATUS_SUCCESS) {
                throw new \Exception($this->getMessage($resp));
            }
        }
    }

    private function final()
    {
        echo "--------------------------"
            . "----------Конец-----------"
            . "--------------------------";
    }

    public function run()
    {
        $this->auth();
        $this->init();
        $this->upload();
        $this->import();
        $this->final();
    }
}

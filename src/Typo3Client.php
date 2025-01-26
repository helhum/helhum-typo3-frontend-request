<?php
declare(strict_types=1);
namespace Helhum\Typo3FrontendRequest;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\PhpProcess;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Crypto\Random;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Session\Backend\SessionBackendInterface;
use TYPO3\CMS\Core\Session\SessionManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;

class Typo3Client
{
    /**
     * @var string
     */
    private $sessionId;

    /**
     * @var string
     */
    private $phpBinary;

    /**
     * @var SessionBackendInterface
     */
    private $sessionBackend;

    public function __construct(string $phpBinary = null, SessionBackendInterface $sessionBackend = null)
    {
        $this->phpBinary = $phpBinary ?? getenv('PHP_BINARY') ?: null;
        $this->sessionBackend = $sessionBackend ?? GeneralUtility::makeInstance(SessionManager::class)->getSessionBackend('FE');
    }

    public function send(RequestInterface $request): ResponseInterface
    {
        $request = $this->ensureAuthentication($request);
        $template = file_get_contents(__DIR__ . '/../res/PHP/Typo3FrontendRequestTemplate.php');
        $arguments = [
            'documentRoot' => getenv('TYPO3_PATH_WEB') ?: rtrim('/', Environment::getPublicPath()),
            'requestUrl' => (string)$request->getUri(),
            'headers' => array_merge(
                $request->getHeaders(),
                [
                    'Accept-Encoding' => [
                        'identity',
                    ],
                ]
            ),
        ];
        $code = str_replace('\'{arguments}\'', var_export($arguments, true), $template);
        $process = new PhpProcess($code, null, null, 0, $this->phpBinary !== null ? [$this->phpBinary] : null);
        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            throw new RequestFailed('Unable to process TYPO3 frontend request', $request, null, $e);
        } finally {
            $this->ensureSessionRemoved();
        }

        $content = $process->getOutput();
        if ($GLOBALS['TYPO3_CONF_VARS']['FE']['compressionLevel']
            && extension_loaded('zlib')
            && MathUtility::canBeInterpretedAsInteger($GLOBALS['TYPO3_CONF_VARS']['FE']['compressionLevel'])
        ) {
            $content = zlib_decode($content) ?: $content;
        }

        $body = new Stream('php://temp', 'rw');
        $body->write($content);
        $body->rewind();

        return new HtmlResponse((string)$body);
    }

    private function ensureAuthentication(RequestInterface $request): RequestInterface
    {
        if (!$request->hasHeader('x-typo3-frontend-user')) {
            return $request;
        }
        $this->sessionId = GeneralUtility::makeInstance(Random::class)->generateRandomHexString(32);
        $frontendUserId = $request->getHeader('x-typo3-frontend-user')[0];
        $this->sessionBackend->set(
            $this->sessionId,
            [
                'ses_id' => $this->sessionId,
                'ses_iplock' => '[DISABLED]',
                'ses_userid' => $frontendUserId,
                'ses_tstamp' => time(),
                'ses_data' => '',
            ]
        );
        $cookieHeader = '';
        if ($request->hasHeader('cookie')) {
            $cookieHeader = $request->getHeader('cookie')[0] . '; ';
        }

        return $request->withHeader(
            'Cookie',
            sprintf(
                '%s%s=%s',
                $cookieHeader,
                FrontendUserAuthentication::getCookieName(),
                $this->sessionId
            )
        );
    }

    private function ensureSessionRemoved(): void
    {
        if (!$this->sessionId) {
            return;
        }
        $this->sessionBackend->remove($this->sessionId);
    }
}

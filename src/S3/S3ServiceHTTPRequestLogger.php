<?php
declare(strict_types=1);

namespace Spy\Package\AwsLogger\S3;

use Aws\CommandInterface;
use Aws\ResultInterface;
use Aws\S3\Exception\S3Exception;
use DateTimeImmutable;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use JsonException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Ramsey\Uuid\Uuid;
use Spy\Package\CentralizedLoggerData\CentralizedLoggerInterface;
use Spy\Package\Data\ContextProvider\ContextProviderInterface;
use Spy\Package\Rest\Cleaner\ServiceHTTPRequestCleanerInterface;
use Spy\Package\Rest\Logging\ServiceHTTPRequest\ServiceHTTPRequestLogData;
use Spy\Package\Rest\Logging\ServiceHTTPRequest\ServiceHTTPRequestStartedLogData;
use Throwable;

/**
 * Class S3ServiceHTTPRequestLogger
 *
 * @package Spy\Package\AwsLogger\S3
 *
 * @phpstan-type NextHandlerCallback callable(CommandInterface $oCommand, RequestInterface $oRequest): PromiseInterface
 */
class S3ServiceHTTPRequestLogger
{
	/** @phpstan-var NextHandlerCallback $fnNextHandler */
	private $fnNextHandler;

	/**
	 * @phpstan-param NextHandlerCallback                   $fnNextHandler
	 * @phpstan-param array<mixed>|ContextProviderInterface $mContext
	 */
	public function __construct(
		callable                                                         $fnNextHandler,
		private readonly CentralizedLoggerInterface                      $oCentralizedLogger,
		private readonly string                                          $strService,
		private readonly ResponseFactoryInterface&StreamFactoryInterface $oPSR17Factory,
		private readonly array|ContextProviderInterface                  $mContext,
		private readonly ServiceHTTPRequestCleanerInterface|null         $oServiceHTTPRequestCleaner,
	)
	{
		$this->fnNextHandler = $fnNextHandler;
	}

	/**
	 * @phpstan-param array<mixed>|ContextProviderInterface $mContext
	 */
	public static function Factory(
		CentralizedLoggerInterface                      $oCentralizedLogger,
		string                                          $strService,
		ResponseFactoryInterface&StreamFactoryInterface $oPSR17Factory,
		array|ContextProviderInterface                  $mContext = [],
		ServiceHTTPRequestCleanerInterface|null         $oServiceHTTPRequestCleaner = null,
	): callable
	{
		return static function (callable $fnNextHandler) use (
			$oCentralizedLogger,
			$strService,
			$oPSR17Factory,
			$mContext,
			$oServiceHTTPRequestCleaner
		): self
		{
			/* @phpstan-ignore new.static (TODO: constructor must be made private or final) */
			return new static(
				$fnNextHandler,
				$oCentralizedLogger,
				$strService,
				$oPSR17Factory,
				$mContext,
				$oServiceHTTPRequestCleaner,
			);
		};
	}

	public function __invoke(CommandInterface $oCommand, RequestInterface $oRequest): PromiseInterface
	{
		$arrContext            = is_array($this->mContext) ? $this->mContext : $this->mContext->getContext();
		$arrContext['Command'] = $oCommand->getName();

		$strKey = $oCommand['Key'];
		if($strKey !== null)
		{
			$arrContext['Key'] = $strKey;
		}

		$oStartDateTime         = new DateTimeImmutable();
		$oRequestStartedLogData = new ServiceHTTPRequestStartedLogData(
			$this->strService,
			Uuid::uuid4()->toString(),
			$oStartDateTime,
			$oRequest,
			$arrContext,
			$this->oServiceHTTPRequestCleaner,
		);

		$this->oCentralizedLogger->logData($oRequestStartedLogData);

		// Removed as it breaks S3 signature
		//$oRequest = $oRequest->withAddedHeader('X-Spy-Request-ID', $oRequestStartedLogData->strRequestID);

		$oPromise = ($this->fnNextHandler)($oCommand, $oRequest);

		return $oPromise->then(
			function (ResultInterface $oResult) use ($oRequestStartedLogData): ResultInterface
			{
				$oResponse = $this->createResponseFromResult($oResult);

				$this->oCentralizedLogger->logData(
					new ServiceHTTPRequestLogData(
						$oRequestStartedLogData,
						new DateTimeImmutable(),
						$oResponse,
						null,
					)
				);

				return $oResult;
			},
			function (Throwable|null $oError) use ($oRequestStartedLogData): PromiseInterface
			{
				$this->oCentralizedLogger->logData(
					new ServiceHTTPRequestLogData(
						$oRequestStartedLogData,
						new DateTimeImmutable(),
						$oError instanceof S3Exception ? $oError->getResponse() : null,
						$oError,
					)
				);

				return Create::rejectionFor($oError);
			}
		);
	}

	/**
	 * @throws JsonException
	 */
	private function createResponseFromResult(ResultInterface $oResult): ResponseInterface
	{
		$arrData     = $oResult->toArray();
		$arrMetadata = $arrData['@metadata'];

		if(array_key_exists('Body', $arrData) && $arrData['Body'] instanceof StreamInterface)
		{
			$oBody = $arrData['Body'];
		}
		else
		{
			unset($arrData['@metadata']);

			$strBody = json_encode($arrData, JSON_THROW_ON_ERROR | CentralizedLoggerInterface::DefaultJsonEncodeFlags);
			$oBody   = $this->oPSR17Factory->createStream($strBody);
		}

		$oResponse = $this->oPSR17Factory->createResponse($arrMetadata['statusCode'] ?? 0);

		foreach($arrMetadata['headers'] as $strName => $strValue)
		{
			$oResponse = $oResponse->withAddedHeader($strName, $strValue);
		}

		return $oResponse->withBody($oBody);
	}
}

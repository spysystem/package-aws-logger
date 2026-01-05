<?php
declare(strict_types=1);

namespace Spy\Package\AwsLogger\S3;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Spy\Package\Rest\Cleaner\ServiceHTTPRequestCleanerInterface;

/**
 * Class S3ServiceHTTPRequestCleaner
 *
 * @package Spy\Package\AwsLogger\S3
 */
class S3ServiceHTTPRequestCleaner implements ServiceHTTPRequestCleanerInterface
{
	public function cleanRequest(RequestInterface $oRequest): RequestInterface
	{
		// Remove the body, as it may be large
		return $oRequest->withBody(Utils::streamFor());
	}

	public function cleanResponse(ResponseInterface $oResponse, RequestInterface $oRequest): ResponseInterface
	{
		// Remove the body, as it may be large
		return $oResponse->withBody(Utils::streamFor());
	}
}

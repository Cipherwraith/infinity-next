<?php namespace App\Exceptions;

use Exception;
use ErrorException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\Debug\Exception\FlattenException as FlattenException;
use Symfony\Component\Debug\ExceptionHandler as SymfonyDisplayer;

class Handler extends ExceptionHandler {
	
	/**
	 * A list of the exception types that should not be reported.
	 *
	 * @var array
	 */
	protected $dontReport = [
		'Symfony\Component\HttpKernel\Exception\HttpException'
	];
	
	/**
	 * Report or log an exception.
	 *
	 * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
	 *
	 * @param  \Exception  $e
	 * @return void
	 */
	public function report(Exception $e)
	{
		return parent::report($e);
	}
	
	/**
	 * Render an exception into an HTTP response.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @param  \Exception  $e
	 * @return \Illuminate\Http\Response
	 */
	public function render($request, Exception $e)
	{
		if ($e instanceof ErrorException)
		{
			// This makes use of a Symfony error handler to make pretty traces.
			$SymfonyDisplayer = new SymfonyDisplayer(config('app.debug'));
			$FlattenException = FlattenException::create($e);
			
			$SymfonyCss       = $SymfonyDisplayer->getStylesheet($FlattenException);
			$SymfonyHtml      = $SymfonyDisplayer->getContent($FlattenException);
			
			return response()->view('errors.500', [
				'error'      => $e,
				'error_css'  => $SymfonyCss,
				'error_html' => $SymfonyHtml,
			], 500);
		}
		else
		{
			return parent::render($request, $e);
		}
	}
	
}

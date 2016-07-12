<?php

namespace Bixie\Invoicemaker\Controller;

use Pagekit\Application as App;
use Pagekit\Application\Exception;
use Bixie\Invoicemaker\InvoicemakerModule;
use Bixie\Invoicemaker\Model\Invoice;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Generator\UrlGenerator;

/**
 * @Route("invoice", name="invoice")
 */
class InvoiceApiController {

	/**
	 * @Route("/", methods="GET")
	 * @Request({"filter": "array", "page":"int"})
	 * @Access("invoicemaker: manage invoices")
	 */
	public function indexAction ($filter = [], $page = 0) {
		$query = Invoice::query();
		$filter = array_merge(array_fill_keys(['template', 'invoice_group', 'order', 'limit'], ''), $filter);

		extract($filter, EXTR_SKIP);

		if (!empty($template)) {
			$query->where('template = ?', [$template]);
		}

		if (!empty($invoice_group)) {
			$query->where('invoice_group = ?', [$invoice_group]);
		}

		if (!preg_match('/^(invoice_number|invoice_group|template|amount|created)\s(asc|desc)$/i', $order, $order)) {
			$order = [1 => 'invoice_number', 2 => 'desc'];
		}


		$limit = (int)$limit ?: 20;
		$count = $query->count();
		$pages = ceil($count / $limit);
		$page = max(0, min($pages - 1, $page));

		$invoices = array_values($query->offset($page * $limit)->limit($limit)->orderBy($order[1], $order[2])->get());

		return compact('invoices', 'pages', 'count');

	}

	/**
	 * @Route("/", methods="POST")
	 * @Route("/{id}", methods="POST", requirements={"id"="\d+"})
	 * @Request({"invoice": "array", "id": "int"}, csrf=true)
	 * @Access("invoicemaker: manage invoices")
	 */
	public function saveAction ($data, $id = 0) {

		if (!$invoice = Invoice::find($id)) {
			$invoice = Invoice::create();
			unset($data['id']);
		}
		
		try {

			$invoice->save($data);

		} catch (Exception $e) {
			App::abort(400, $e->getMessage());
		}

		return ['message' => 'success', 'invoice' => $invoice];
	}

	/**
	 * @Route("/{id}", methods="DELETE", requirements={"id"="\d+"})
	 * @Request({"id": "int"}, csrf=true)
	 * @Access("invoicemaker: manage invoices")
	 */
	public function deleteAction ($id) {
		if ($invoice = Invoice::find($id)) {

			$invoice->delete();
		}

		return ['message' => 'success'];
	}

	/**
	 * @Route("/bulk", methods="DELETE")
	 * @Request({"ids": "array"}, csrf=true)
	 * @Access("invoicemaker: manage invoices")
	 */
	public function bulkDeleteAction ($ids = []) {
		foreach (array_filter($ids) as $id) {
			$this->deleteAction($id);
		}

		return ['message' => 'success'];
	}

	/**
	 * @Route("/rerender/{id}", name="rerender")
	 * @Request({"id": "integer"})
	 * @Access("invoicemaker: manage invoices")
	 */
	public function reRenderPdfAction($id) {
		/** @var InvoicemakerModule $invoicemaker */
		$invoicemaker = App::module('bixie/invoicemaker');

		if (!$invoice = Invoice::find($id)) {
			App::abort(404, __('Invoice not found'));
		}

		try {

			if ($invoicemaker->renderPdfFile($invoice)) {

				$invoice->save([
					'pdf_file' => $invoice->getPdfFilename()
				]);

			}

		} catch (\Exception $e) {
			App::abort(500, __('Error in creating PDF file'));
		}

		return ['message' => 'success'];
	}

	/**
	 * @Route("/pdf/{invoice_number}", name="pdf")
	 * @Request({"invoice_number": "string", "key": "string", "inline": "bool"})
	 * @Access("invoicemaker: manage invoices")
	 * @param integer $invoice_number Invoice bumber
	 * @param string  $key            session key
	 * @param bool    $inline
	 * @return StreamedResponse|BinaryFileResponse
	 */
	public function pdfAction($invoice_number, $key, $inline = false) {
		/** @var InvoicemakerModule $invoicemaker */
		$invoicemaker = App::module('bixie/invoicemaker');

		if (!$invoice = Invoice::byInvoiceNumber($invoice_number)) {
			App::abort(404, __('Invoice not found'));
		}

		if (!$invoicemaker->checkDownloadKey($invoice, $key)) {
			App::abort(400, __('Key not valid.'));
		}


		if ($filename = $invoice->pdf_file and $path = $invoicemaker->getPdfPath() . '/' . $filename) {
			//existing file
			$response = new BinaryFileResponse($path);

		} else {
			//generate stream
			$filename = $invoice->getPdfFilename();
			$response = new StreamedResponse();
			$response->setCallback(function () use ($invoicemaker, $invoice) {
				echo $invoicemaker->renderPdfString($invoice);
			});
			$response->setStatusCode(200);
			$response->headers->set('Content-Type', 'application/pdf; charset=utf-8');

		}

		$response->headers->set('Content-Disposition', $response->headers->makeDisposition(
			($inline ? ResponseHeaderBag::DISPOSITION_INLINE: ResponseHeaderBag::DISPOSITION_ATTACHMENT),
			$filename,
			mb_convert_encoding($filename, 'ASCII')
		));

		return $response;

	}

	/**
	 * @Route("/html/{invoice_number}", name="html")
	 * @Request({"invoice_number": "string", "key": "string"})
	 * @param integer $invoice_number Invoice bumber
	 * @param string  $key            session key
	 * @return StreamedResponse
	 */
	public function htmlAction($invoice_number, $key) {
		/** @var InvoicemakerModule $invoicemaker */
		$invoicemaker = App::module('bixie/invoicemaker');

		if (!$invoice = Invoice::byInvoiceNumber($invoice_number)) {
			App::abort(404, __('Invoice not found'));
		}

		if (!$invoicemaker->checkDownloadKey($invoice, $key)) {
			App::abort(400, __('Key not valid.'));
		}

		$filename = $invoice->getPdfFilename();

		$response = new StreamedResponse();
		$response->setCallback(function () use ($invoicemaker, $invoice) {
			echo str_replace(App::path(), App::url()->getStatic('/', [], UrlGenerator::ABSOLUTE_URL), $invoicemaker->renderHtml($invoice));
		});
		$response->setStatusCode(200);
		$response->headers->set('Content-Type', 'text/html; charset=utf-8');
		$response->headers->set('Content-Disposition', $response->headers->makeDisposition(
			ResponseHeaderBag::DISPOSITION_INLINE,
			$filename,
			mb_convert_encoding($filename, 'ASCII')
		));

		return $response;

	}

}

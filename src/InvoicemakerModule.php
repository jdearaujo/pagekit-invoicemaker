<?php

namespace Bixie\Invoicemaker;

use Bixie\Invoicemaker\Invoice\Debtor;
use Bixie\Invoicemaker\Invoice\InvoiceLineCollection;
use Bixie\Invoicemaker\Model\Invoice;
use Bixie\Invoicemaker\Settings\InvoiceGroup;
use Bixie\Invoicemaker\Settings\Template;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Dompdf\Dompdf;
use Pagekit\Application as App;
use Pagekit\Module\Module;
use Pagekit\Util\Arr;

class InvoicemakerModule extends Module {
	/**
	 * @var InvoiceGroup[]
	 */
	protected $invoice_groups;
	/**
	 * @var Template[]
	 */
	protected $templates;
	/**
	 * @var array
	 */
	protected $pdf_templates = [];

	/**
	 * {@inheritdoc}
	 */
	public function main (App $app) {

		$util = $app['db']->getUtility();
		if ($util->tableExists('@invoicemaker_invoice') === false) {
			$util->createTable('@invoicemaker_invoice', function ($table) {
				$table->addColumn('id', 'integer', ['unsigned' => true, 'length' => 10, 'autoincrement' => true]);
				$table->addColumn('template', 'string', ['length' => 255]);
				$table->addColumn('created', 'datetime');
				$table->addColumn('invoice_number', 'string', ['length' => 255]);
				$table->addColumn('invoice_group', 'string', ['length' => 255]);
				$table->addColumn('amount', 'decimal', ['precision' => 9, 'scale' => 2]);
				$table->addColumn('ext_key', 'string', ['length' => 255, 'notnull' => false]);
				$table->addColumn('pdf_file', 'string', ['length' => 255, 'notnull' => false]);
				$table->addColumn('debtor', 'json_array', ['notnull' => false]);
				$table->addColumn('invoice_lines', 'json_array', ['notnull' => false]);
				$table->addColumn('data', 'json_array', ['notnull' => false]);
				$table->setPrimaryKey(['id']);
				$table->addIndex(['ext_key'], 'INVOICEMAKER_INVOICE_EXT_KEY');
				$table->addUniqueIndex(['invoice_number'], '@INVOICEMAKER_INVOICE_INVOICE_NUMBER');
			});
		}

		$app['invoicemaker.factory'] = function ($app) {
			return new InvoiceFactory($app);
		};
		
		$this->registerPdfTemplate('default', 'bixie/invoicemaker:templates/default');
	}

	/**
	 * @return InvoiceGroup[]
	 */
	public function getInvoiceGroups () {
		if (!isset($this->invoice_groups)) {
			$this->invoice_groups = array_map(function ($data) {
			    return new InvoiceGroup($data);
			}, $this->config('invoice_groups', []));
		}
		return $this->invoice_groups;
	}

	/**
	 * @param string $invoice_group
	 * @return InvoiceGroup|bool
	 */
	public function getInvoiceGroup ($invoice_group) {
		$this->getInvoiceGroups();
		$groups = array_filter($this->invoice_groups, function ($invoiceGroup) use ($invoice_group) {
		    return $invoiceGroup->name == $invoice_group;
		});
		return isset($groups[0]) ? $groups[0] : false;
	}

	/**
	 * @return array|Template[]
	 */
	public function getTemplates () {
		if (!isset($this->templates)) {
			$this->templates = array_map(function ($data) {
			    return new Template($this, $data);
			}, $this->config('templates', []));
		}
		return $this->templates;
	}

	/**
	 * @param string $template_name
	 * @return Template|bool
	 */
	public function getTemplate ($template_name) {
		$this->getTemplates();
		$templates = array_filter($this->templates, function ($template) use ($template_name) {
			return $template->name == $template_name;
		});
		return isset($templates[0]) ? $templates[0] : false;
	}

	/**
	 * @param $name
	 * @param $path
	 */
	public function registerPdfTemplate ($name, $path) {
		$this->pdf_templates[$name] = $path;
	}

	/**
	 * @return array
	 */
	public function getPdfTemplates () {
		return array_keys($this->pdf_templates);
	}

	/**
	 * @param $name
	 * @return bool|mixed
	 */
	public function getPdfTemplate ($name) {
		return isset($this->pdf_templates[$name]) ? $this->pdf_templates[$name] : false;
	}

	/**
	 * @param Debtor                $debtor
	 * @param InvoiceLineCollection $invoice_lines
	 * @param string                $template_name
	 * @param string                $invoice_group
	 * @param array                 $data
	 * @return Invoice
	 */
	public function createInvoice (Debtor $debtor, InvoiceLineCollection $invoice_lines, $template_name, $invoice_group, $data =[]) {

		if (!$invoiceGroup = $this->getInvoiceGroup($invoice_group)) {
			throw new InvoicemakerException(sprintf('Invoicegroup %s not found', $invoice_group), 400);
		}

		if (!$template = $this->getTemplate($template_name)) {
			throw new InvoicemakerException(sprintf('Template %s not found', $template_name), 400);
		}

		$invoice = Invoice::create([
			'debtor' => $debtor,
			'invoice_lines' => $invoice_lines,
			'created' => new \DateTime(),
			'amount' => Arr::get($data, 'amount', ''),
			'ext_key' => Arr::get($data, 'ext_key', ''),
			'template' => $template->name,
			'invoice_number' => $this->getInvoiceNumber($invoiceGroup, $data),
			'invoice_group' => $invoiceGroup->name
		]);

		try {

			$invoice->save();

		} catch (\Exception $e) {
			if ($e instanceof UniqueConstraintViolationException) {
				throw new InvoicemakerException(sprintf('Invoice number %s already exists!', $invoice->invoice_number), $e->getCode(), $e);
			}
			throw new InvoicemakerException('Error in saving invoice to database', $e->getCode(), $e);
		}

		try {

			if ($this->renderPdfFile($invoice)) {
				
				$invoice->save([
					'pdf_file' => $invoice->getPdfFilename()
				]);

			}

		} catch (\Exception $e) {
			throw new InvoicemakerException('Error in creating PDF file', $e->getCode(), $e);
		}

		return $invoice;
	}

	/**
	 * @param InvoiceGroup $invoiceGroup
	 * @param array        $data
	 * @return string
	 */
	public function getInvoiceNumber (InvoiceGroup $invoiceGroup, $data = []) {
		if ($last_invoice_number = Invoice::lastInvoiceNumber($invoiceGroup->name)) {
			return $invoiceGroup->getInvoiceNumber((intval(substr($last_invoice_number, $invoiceGroup->digits * -1), 10) + 1), $data);
		}
		return $invoiceGroup->getInvoiceNumber(1, $data);
	}

	/**
	 * @param Invoice $invoice
	 * @param array   $params
	 * @return string
	 */
	public function renderHtml (Invoice $invoice, $params = []) {
		return $this->getTemplate($invoice->template)->mergeParams($params)->renderHtml($invoice);
	}

	/**
	 * @param Invoice $invoice
	 * @return string
	 */
	public function renderPdfFile (Invoice $invoice) {
		if ($this->config['save_pdfs'] and $path = $this->getPdfPath()) {
			return file_put_contents($path . '/' . $invoice->getPdfFilename(), $this->renderPdfString($invoice)) > 0;
		}
		return false;
	}

	/**
	 * @param Invoice $invoice
	 * @return string
	 */
	public function renderPdfString (Invoice $invoice) {
		$dompdf = new Dompdf();
		$dompdf->loadHtml($this->renderHtml($invoice));
		$dompdf->setPaper('A4', 'portrait');
		$dompdf->render();
		return $dompdf->output();
	}

	/**
	 * @param Invoice $invoice
	 * @return string
	 */
	public function getDownloadKey (Invoice $invoice) {
		$session_key = $this->getSessionKey($invoice);
		App::session()->set("_bixieInvoice.downloadkey.{$invoice->id}", $session_key);
		return $session_key;
	}

	/**
	 * @param Invoice $invoice
	 * @param         $key
	 * @return bool
	 */
	public function checkDownloadKey (Invoice $invoice, $key) {
		$check_key = $this->getSessionKey($invoice);
		if ($invoice->id > 0
			and $check_key === $key
			and $key === App::session()->get("_bixieInvoice.downloadkey.{$invoice->id}")) {

			return true;
		}
		return false;
	}

	/**
	 * @return string
	 */
	public function getPdfPath () {
		$root = strtr(App::path(), '\\', '/');
		$path = $this->normalizePath($root . '/' . $this->config['pdf_path']);
		if (!is_dir($path)) {
			App::file()->makeDir($path);
		}
		return $path;
	}

	/**
	 * @param Invoice $invoice
	 * @return string
	 */
	protected function getSessionKey (Invoice $invoice) {
		return sha1(App::system()->config('key') . '.' . App::session()->getId() . '.' . $invoice->id  );
	}

	/**
	 * Normalizes the given path
	 * @param  string $path
	 * @return string
	 */
	protected function normalizePath ($path) {
		$path = str_replace(['\\', '//'], '/', $path);
		$prefix = preg_match('|^(?P<prefix>([a-zA-Z]+:)?//?)|', $path, $matches) ? $matches['prefix'] : '';
		$path = substr($path, strlen($prefix));
		$parts = array_filter(explode('/', $path), 'strlen');
		$tokens = [];

		foreach ($parts as $part) {
			if ('..' === $part) {
				array_pop($tokens);
			} elseif ('.' !== $part) {
				array_push($tokens, $part);
			}
		}

		return $prefix . implode('/', $tokens);
	}

}

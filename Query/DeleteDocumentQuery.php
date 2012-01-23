<?php
namespace FS\SolrBundle\Query;

class DeleteDocumentQuery extends AbstractQuery {
	
	/**
	 * 
	 * @var \SolrInputDocument
	 */
	private $document = null;
	
	public function __construct(\SolrInputDocument $document) {
		$this->document = $document;
	}
	
	public function getQueryString() {
		$idField = $this->document->getField('id');
		$documentNameField = $this->document->getField('document_name_s');
		
		$query = 'id:'.$idField->values[0].' AND document_name_s:'.$documentNameField->values[0];
		
		return $query;
	}
}

?>
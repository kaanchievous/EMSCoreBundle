<?php

namespace EMS\CoreBundle\Form\DataField;

use EMS\CoreBundle\Entity\DataField;
use EMS\CoreBundle\Entity\FieldType;
use EMS\CoreBundle\Form\Field\AssetType;
use EMS\CoreBundle\Form\Field\IconPickerType;
use EMS\CoreBundle\Service\FileService;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Form\FormRegistryInterface;
	
/**
 * Defined a Container content type.
 * It's used to logically groups subfields together. However a Container is invisible in Elastic search.
 *
 * @author Mathieu De Keyzer <ems@theus.be>
 *
 */
class FileAttachmentFieldType extends DataFieldType {

	/**@var FileService */
	private $fileService;
	
	
	
	public function __construct(AuthorizationCheckerInterface $authorizationChecker, FormRegistryInterface $formRegistry, FileService $fileService) {
		parent::__construct($authorizationChecker, $formRegistry);
		$this->fileService= $fileService;
	}

	/**
	 * Get a icon to visually identify a FieldType
	 *
	 * @return string
	 */
	public static function getIcon() {
		return 'fa fa-file-text-o';
	}

	/**
	 *
	 * {@inheritdoc}
	 *
	 */
	public function getLabel(){
		return 'File Attachment (indexed) field';
	}

	/**
	 *
	 * {@inheritdoc}
	 *
	 */
	public function buildForm(FormBuilderInterface $builder, array $options) {
		/** @var FieldType $fieldType */
		$fieldType = $options ['metadata'];
		$builder->add ( 'value', AssetType::class, [
				'label' => (null != $options ['label']?$options ['label']:$fieldType->getName()),
				'disabled'=> $this->isDisabled($options),
				'required' => false,
		] );
	}
	

	public function reverseViewTransform($data, FieldType $fieldType) {
		$dataField = parent::reverseViewTransform($data, $fieldType);
		
		if(!empty($dataField->getRawData()) && !empty($dataField->getRawData()['value']['sha1'])){
			$rawData = $dataField->getRawData()['value'];
			$rawData['content'] = $this->fileService->getBase64($rawData['sha1']);
			if(!$rawData['content']){
				unset($rawData['content']);
			}
			$rawData['filesize'] = $this->fileService->getSize($rawData['sha1']);
			if(!$rawData['filesize']){
				unset($rawData['filesize']);
			}
			
			$dataField->setRawData($rawData);
		}
		else{
			$dataField->setRawData(['content' => ""]);
		}
		return $dataField;
	}	
	
	
	
	/**
	 *
	 * {@inheritDoc}
	 * @see \EMS\CoreBundle\Form\DataField\DataFieldType::getBlockPrefix()
	 */
	public function getBlockPrefix() {
		return 'bypassdatafield';
	}
	
	public function viewTransform(DataField $dataField) {
		
		$rawData = $dataField->getRawData();
		
		if(!empty($rawData) && !empty($rawData['sha1'])){
			unset($rawData['content']);
			unset($rawData['filesize']);
		}
		else {
			$rawData = [];			
		}
		return ['value' => $rawData];
	}
	
	
	/**
	 *
	 * {@inheritdoc}
	 *
	 */
	public function buildOptionsForm(FormBuilderInterface $builder, array $options) {
		parent::buildOptionsForm ( $builder, $options );
		$optionsForm = $builder->get ( 'options' );
		$optionsForm->remove ( 'mappingOptions' );
		$optionsForm->get ( 'displayOptions' )
			->add ( 'icon', IconPickerType::class, [ 
					'required' => false 
			] );
	}


	/**
	 *
	 * {@inheritdoc}
	 *
	 */
	public static function buildObjectArray(DataField $data, array &$out) {
		if (! $data->getFieldType ()->getDeleted ()) {
			/**
			 * by default it serialize the text value.
			 * It can be overrided.
			 */
			$out [$data->getFieldType ()->getName ()] = $data->getRawData();
		}
	}
	
	/**
	 *
	 * {@inheritdoc}
	 *
	 */
	public function configureOptions(OptionsResolver $resolver) {
		/* set the default option value for this kind of compound field */
		parent::configureOptions ( $resolver );
		$resolver->setDefault ( 'icon', null );
	}
	
	/**
	 * {@inheritdoc}
	 */
	public static function generateMapping(FieldType $current, $withPipeline){
		$body = [
				"type" => "nested",
				"properties" => [
						"mimetype" => [
							"type" => "string",
							"index" => "not_analyzed"
						],
						"sha1" => [
							"type" => "string",
							"index" => "not_analyzed"
						],
						"filename" => [
							"type" => "string",
						],
						"filesize" => [
							"type" => "long",
						]
				],
			];
		
		if($withPipeline) {
			$body['properties']['content'] = [
				"type" => "text",
				"index" => "no",
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
						'ignore_above' => 256
					]
				]
			];
		}
		
		
		return [
			$current->getName() => array_merge($body,  array_filter($current->getMappingOptions()))
		];
	}
	
	/**
	 * {@inheritdoc}
	 */
	public static function generatePipeline(FieldType $current){
		return [
			"attachment" => [
				'field' => $current->getName().'.content',
				'target_field' => $current->getName().'.attachment',
				'indexed_chars' => 1000000,
			]
		];
	}
}
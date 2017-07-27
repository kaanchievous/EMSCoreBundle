<?php

namespace EMS\CoreBundle\Form\DataField;


use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use EMS\CoreBundle\Entity\FieldType;
use EMS\CoreBundle\Entity\DataField;
				
/**
 * Defined a Container content type.
 * It's used to logically groups subfields together. However a Container is invisible in Elastic search.
 *
 * @author Mathieu De Keyzer <ems@theus.be>
 *        
 */
 class JSONFieldType extends DataFieldType {
 	/* to refactor */
	/**
	 *
	 * {@inheritdoc}
	 *
	 */
	public function getLabel(){
		return 'JSON field';
	}
	
	/**
	 *
	 * {@inheritdoc}
	 *
	 */
	public static function getIcon(){
		return 'fa fa-code';
	}

    /**
     * {@inheritdoc}
     */
	public function buildForm(FormBuilderInterface $builder, array $options) {
		/** @var FieldType $fieldType */
		$fieldType = $builder->getOptions () ['metadata'];
		$builder->add ( 'value', TextareaType::class, [
				'attr' => [ 
						'rows' => $options['rows'],
				],
				'label' => (null != $options ['label']?$options ['label']:$fieldType->getName()),
				'required' => false,
				'disabled'=> $this->isDisabled($options),
		]);
	}
	
	/**
	 * 
	 * {@inheritDoc}
	 * @see \EMS\CoreBundle\Form\DataField\DataFieldType::viewTransform()
	 */
	public function viewTransform(DataField $dataField) {
		return [ 'value' => json_encode($dataField->getRawData()) ];
	}

	public function reverseViewTransform($input, FieldType $fieldType) {
		$dataValues = parent::reverseViewTransform($input, $fieldType);
		$options = $fieldType->getOptions();
		if($input === null){
			$dataValues->setRawData(null);
		}
		else{
			$data = @json_decode($input['value']);
			if ($data === null
					&& json_last_error() !== JSON_ERROR_NONE) {
					$dataValues->setRawData($input['value']);
			}
			else{
				$dataValues->setRawData($data);					
			}
		}
		return $dataValues;
	}
	

	public static function buildObjectArray(DataField $data, array &$out) {
		if (! $data->getFieldType ()->getDeleted ()) {
			/**
			 * by default it serialize the text value.
			 * It can be overrided.
			 */
			$out [$data->getFieldType ()->getName ()] = $data->getRawData ();
		}
	}
	
	
	/**
	 *
	 * {@inheritDoc}
	 * @see \EMS\CoreBundle\Form\DataField\DataFieldType::getBlockPrefix()
	 */
	public function getBlockPrefix() {
		return 'bypassdatafield';
	}
	
	/**
	 *
	 * {@inheritdoc}
	 *
	 */
	public function isValid(DataField &$dataField){
		$isValid = parent::isValid($dataField);
		$rawData = $dataField->getRawData();
		if($rawData !== null){
			$data = @json_decode($rawData);
				
			if(json_last_error() !== JSON_ERROR_NONE) {
				$isValid = FALSE;
				$dataField->addMessage("Not a valid JSON");
			}
		}
		return $isValid;
	}


	/**
	 * {@inheritdoc}
	 */
	public function buildView(FormView $view, FormInterface $form, array $options) {
		/*get options for twig context*/
		parent::buildView($view, $form, $options);
		$view->vars ['icon'] = $options ['icon'];
	}
	
	/**
	 * {@inheritdoc}
	 */
	public function configureOptions(OptionsResolver $resolver)
	{
		/*set the default option value for this kind of compound field*/
		parent::configureOptions($resolver);
		$resolver->setDefault('icon', null);
		$resolver->setDefault('rows', null);
	}
	

	/**
	 *
	 * {@inheritdoc}
	 *
	 */
	public static function generateMapping(FieldType $current, $withPipeline){
		if(!empty($current->getMappingOptions()) && !empty($current->getMappingOptions()['mappingOptions'])){
			return [ $current->getName() =>  json_decode($current->getMappingOptions()['mappingOptions' ])];
		}
		return [];
	}
	
	/**
	 *
	 * {@inheritdoc}
	 *
	 */
	public function buildOptionsForm(FormBuilderInterface $builder, array $options) {
		parent::buildOptionsForm ( $builder, $options );
		$optionsForm = $builder->get ( 'options' );
		
		$optionsForm->get ( 'mappingOptions' )->remove('index')->remove('analyzer')->add('mappingOptions', TextareaType::class, [ 
				'required' => false,
				'attr' => [
					'rows' => 8,
				],
		] );
		$optionsForm->get ( 'displayOptions' )->add ( 'rows', IntegerType::class, [
				'required' => false,
		]);
	}
}
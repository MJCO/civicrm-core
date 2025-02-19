<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * This class generates form components for offline membership form.
 */
class CRM_Member_Form_Membership extends CRM_Member_Form {

  protected $_memType = NULL;

  public $_mode;

  public $_contributeMode = 'direct';

  protected $_recurMembershipTypes;

  protected $_memTypeSelected;

  /**
   * Display name of the member.
   *
   * @var string
   */
  protected $_memberDisplayName = NULL;

  /**
   * email of the person paying for the membership (used for receipts)
   * @var string
   */
  protected $_memberEmail = NULL;

  /**
   * Contact ID of the member.
   *
   * @var int
   */
  public $_contactID = NULL;

  /**
   * Display name of the person paying for the membership (used for receipts)
   *
   * @var string
   */
  protected $_contributorDisplayName = NULL;

  /**
   * Email of the person paying for the membership (used for receipts).
   *
   * @var string
   */
  protected $_contributorEmail;

  /**
   * email of the person paying for the membership (used for receipts)
   *
   * @var int
   */
  protected $_contributorContactID = NULL;

  /**
   * ID of the person the receipt is to go to.
   *
   * @var int
   */
  protected $_receiptContactId = NULL;

  /**
   * Keep a class variable for ALL membership IDs so
   * postProcess hook function can do something with it
   *
   * @var array
   */
  protected $_membershipIDs = [];

  /**
   * Set entity fields to be assigned to the form.
   */
  protected function setEntityFields() {
    $this->entityFields = [
      'join_date' => [
        'name' => 'join_date',
        'description' => ts('Member Since'),
      ],
      'start_date' => [
        'name' => 'start_date',
        'description' => ts('Start Date'),
      ],
      'end_date' => [
        'name' => 'end_date',
        'description' => ts('End Date'),
      ],
    ];
  }

  /**
   * Set the delete message.
   *
   * We do this from the constructor in order to do a translation.
   */
  public function setDeleteMessage() {
    $this->deleteMessage = '<span class="font-red bold">'
      . ts('WARNING: Deleting this membership will also delete any related payment (contribution) records.' . ts('This action cannot be undone.')
        . '</span><p>'
        . ts('Consider modifying the membership status instead if you want to maintain an audit trail and avoid losing payment data. You can set the status to Cancelled by editing the membership and clicking the Status Override checkbox.')
          . '</p><p>'
        . ts("Click 'Delete' if you want to continue.") . '</p>');
  }

  /**
   * Overriding this entity trait function as not yet tested.
   *
   * We continue to rely on legacy handling.
   */
  public function addCustomDataToForm() {}

  /**
   * Overriding this entity trait function as not yet tested.
   *
   * We continue to rely on legacy handling.
   */
  public function addFormButtons() {}

  /**
   * Get selected membership type from the form values.
   *
   * @param array $priceSet
   * @param array $params
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public static function getSelectedMemberships($priceSet, $params) {
    $memTypeSelected = [];
    $priceFieldIDS = self::getPriceFieldIDs($params, $priceSet);
    if (isset($params['membership_type_id']) && !empty($params['membership_type_id'][1])) {
      $memTypeSelected = [$params['membership_type_id'][1] => $params['membership_type_id'][1]];
    }
    else {
      foreach ($priceFieldIDS as $priceFieldId) {
        if ($id = CRM_Core_DAO::getFieldValue('CRM_Price_DAO_PriceFieldValue', $priceFieldId, 'membership_type_id')) {
          $memTypeSelected[$id] = $id;
        }
      }
    }
    return $memTypeSelected;
  }

  /**
   * Extract price set fields and values from $params.
   *
   * @param array $params
   * @param array $priceSet
   *
   * @return array
   */
  public static function getPriceFieldIDs($params, $priceSet) {
    $priceFieldIDS = [];
    if (isset($priceSet['fields']) && is_array($priceSet['fields'])) {
      foreach ($priceSet['fields'] as $fieldId => $field) {
        if (!empty($params['price_' . $fieldId])) {
          if (is_array($params['price_' . $fieldId])) {
            foreach ($params['price_' . $fieldId] as $priceFldVal => $isSet) {
              if ($isSet) {
                $priceFieldIDS[] = $priceFldVal;
              }
            }
          }
          elseif (!$field['is_enter_qty']) {
            $priceFieldIDS[] = $params['price_' . $fieldId];
          }
        }
      }
    }
    return $priceFieldIDS;
  }

  /**
   * Form preProcess function.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function preProcess() {
    // This string makes up part of the class names, differentiating them (not sure why) from the membership fields.
    $this->assign('formClass', 'membership');
    parent::preProcess();

    // get price set id.
    $this->_priceSetId = $_GET['priceSetId'] ?? NULL;
    $this->set('priceSetId', $this->_priceSetId);
    $this->assign('priceSetId', $this->_priceSetId);

    if ($this->_action & CRM_Core_Action::DELETE) {
      $contributionID = CRM_Member_BAO_Membership::getMembershipContributionId($this->_id);
      // check delete permission for contribution
      if ($this->_id && $contributionID && !CRM_Core_Permission::checkActionPermission('CiviContribute', $this->_action)) {
        CRM_Core_Error::statusBounce(ts("This Membership is linked to a contribution. You must have 'delete in CiviContribute' permission in order to delete this record."));
      }
    }

    if ($this->_action & CRM_Core_Action::ADD) {
      if ($this->_contactID) {
        //check whether contact has a current membership so we can alert user that they may want to do a renewal instead
        $contactMemberships = [];
        $memParams = ['contact_id' => $this->_contactID];
        CRM_Member_BAO_Membership::getValues($memParams, $contactMemberships, TRUE);
        $cMemTypes = [];
        foreach ($contactMemberships as $mem) {
          $cMemTypes[] = $mem['membership_type_id'];
        }
        if (count($cMemTypes) > 0) {
          foreach ($cMemTypes as $memTypeID) {
            $memberorgs[$memTypeID] = CRM_Member_BAO_MembershipType::getMembershipType($memTypeID)['member_of_contact_id'];
          }
          $mems_by_org = [];
          foreach ($contactMemberships as $mem) {
            $mem['member_of_contact_id'] = $memberorgs[$mem['membership_type_id']] ?? NULL;
            if (!empty($mem['membership_end_date'])) {
              $mem['membership_end_date'] = CRM_Utils_Date::customFormat($mem['membership_end_date']);
            }
            $mem['membership_type'] = CRM_Core_DAO::getFieldValue('CRM_Member_DAO_MembershipType',
              $mem['membership_type_id'],
              'name', 'id'
            );
            $mem['membership_status'] = CRM_Core_DAO::getFieldValue('CRM_Member_DAO_MembershipStatus',
              $mem['status_id'],
              'label', 'id'
            );
            $mem['renewUrl'] = CRM_Utils_System::url('civicrm/contact/view/membership',
              "reset=1&action=renew&cid={$this->_contactID}&id={$mem['id']}&context=membership&selectedChild=member"
              . ($this->_mode ? '&mode=live' : '')
            );
            $mem['membershipTab'] = CRM_Utils_System::url('civicrm/contact/view',
              "reset=1&force=1&cid={$this->_contactID}&selectedChild=member"
            );
            $mems_by_org[$mem['member_of_contact_id']] = $mem;
          }
          $this->assign('existingContactMemberships', $mems_by_org);
        }
      }
      else {
        // In standalone mode we don't have a contact id yet so lookup will be done client-side with this script:
        $resources = CRM_Core_Resources::singleton();
        $resources->addScriptFile('civicrm', 'templates/CRM/Member/Form/MembershipStandalone.js');
        $passthru = [
          'typeorgs' => CRM_Member_BAO_MembershipType::getMembershipTypeOrganization(),
          'memtypes' => CRM_Core_PseudoConstant::get('CRM_Member_BAO_Membership', 'membership_type_id'),
          'statuses' => CRM_Core_PseudoConstant::get('CRM_Member_BAO_Membership', 'status_id'),
        ];
        $resources->addSetting(['existingMems' => $passthru]);
      }
    }

    if (!$this->_memType) {
      $params = CRM_Utils_Request::exportValues();
      if (!empty($params['membership_type_id'][1])) {
        $this->_memType = $params['membership_type_id'][1];
      }
    }

    // Add custom data to form
    CRM_Custom_Form_CustomData::addToForm($this, $this->_memType);
    $this->setPageTitle(ts('Membership'));
  }

  /**
   * Set default values for the form.
   */
  public function setDefaultValues() {

    if ($this->_priceSetId) {
      return CRM_Price_BAO_PriceSet::setDefaultPriceSet($this, $defaults);
    }

    $defaults = parent::setDefaultValues();

    //setting default join date and receive date
    if ($this->_action == CRM_Core_Action::ADD) {
      $defaults['receive_date'] = CRM_Utils_Time::date('Y-m-d H:i:s');
    }

    $defaults['num_terms'] = 1;

    if (!empty($defaults['id'])) {
      $contributionId = CRM_Core_DAO::singleValueQuery("
SELECT contribution_id
FROM civicrm_membership_payment
WHERE membership_id = $this->_id
ORDER BY contribution_id
DESC limit 1");

      if ($contributionId) {
        $defaults['record_contribution'] = $contributionId;
      }
    }
    else {
      if ($this->_contactID) {
        $defaults['contact_id'] = $this->_contactID;
      }
    }

    //set Soft Credit Type to Gift by default
    $scTypes = CRM_Core_OptionGroup::values('soft_credit_type');
    $defaults['soft_credit_type_id'] = CRM_Utils_Array::value(ts('Gift'), array_flip($scTypes));

    //CRM-13420
    if (empty($defaults['payment_instrument_id'])) {
      $defaults['payment_instrument_id'] = key(CRM_Core_OptionGroup::values('payment_instrument', FALSE, FALSE, FALSE, 'AND is_default = 1'));
    }

    // User must explicitly choose to send a receipt in both add and update mode.
    $defaults['send_receipt'] = 0;

    if ($this->_action & CRM_Core_Action::UPDATE) {
      // in this mode by default uncheck this checkbox
      unset($defaults['record_contribution']);
    }

    $subscriptionCancelled = FALSE;
    if (!empty($defaults['id'])) {
      $subscriptionCancelled = CRM_Member_BAO_Membership::isSubscriptionCancelled($this->_id);
    }

    $alreadyAutoRenew = FALSE;
    if (!empty($defaults['contribution_recur_id']) && !$subscriptionCancelled) {
      $defaults['auto_renew'] = 1;
      $alreadyAutoRenew = TRUE;
    }
    $this->assign('alreadyAutoRenew', $alreadyAutoRenew);

    $this->assign('member_is_test', $defaults['member_is_test'] ?? NULL);
    $this->assign('membership_status_id', $defaults['status_id'] ?? NULL);
    $this->assign('is_pay_later', !empty($defaults['is_pay_later']));

    if ($this->_mode) {
      $defaults = $this->getBillingDefaults($defaults);
    }

    //setting default join date if there is no join date
    if (empty($defaults['join_date'])) {
      $defaults['join_date'] = CRM_Utils_Time::date('Y-m-d');
    }

    if (!empty($defaults['membership_end_date'])) {
      $this->assign('endDate', $defaults['membership_end_date']);
    }

    return $defaults;
  }

  /**
   * Build the form object.
   *
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm() {

    $this->buildQuickEntityForm();
    $this->assign('currency_symbol', CRM_Core_BAO_Country::defaultCurrencySymbol());
    $isUpdateToExistingRecurringMembership = $this->isUpdateToExistingRecurringMembership();
    // build price set form.
    $buildPriceSet = FALSE;
    if ($this->_priceSetId || !empty($_POST['price_set_id'])) {
      if (!empty($_POST['price_set_id'])) {
        $buildPriceSet = TRUE;
      }
      $getOnlyPriceSetElements = TRUE;
      if (!$this->_priceSetId) {
        $this->_priceSetId = $_POST['price_set_id'];
        $getOnlyPriceSetElements = FALSE;
      }

      $this->set('priceSetId', $this->_priceSetId);
      CRM_Price_BAO_PriceSet::buildPriceSet($this);

      $optionsMembershipTypes = [];
      foreach ($this->_priceSet['fields'] as $pField) {
        if (empty($pField['options'])) {
          continue;
        }
        foreach ($pField['options'] as $opId => $opValues) {
          $optionsMembershipTypes[$opId] = CRM_Utils_Array::value('membership_type_id', $opValues, 0);
        }
      }

      $this->assign('autoRenewOption', CRM_Price_BAO_PriceSet::checkAutoRenewForPriceSet($this->_priceSetId));

      $this->assign('optionsMembershipTypes', $optionsMembershipTypes);
      $this->assign('contributionType', CRM_Utils_Array::value('financial_type_id', $this->_priceSet));

      // get only price set form elements.
      if ($getOnlyPriceSetElements) {
        return;
      }
    }

    // use to build form during form rule.
    $this->assign('buildPriceSet', $buildPriceSet);

    if ($this->_action & CRM_Core_Action::ADD) {
      $buildPriceSet = FALSE;
      $priceSets = CRM_Price_BAO_PriceSet::getAssoc(FALSE, 'CiviMember');
      if (!empty($priceSets)) {
        $buildPriceSet = TRUE;
      }

      if ($buildPriceSet) {
        $this->add('select', 'price_set_id', ts('Choose price set'),
          [
            '' => ts('Choose price set'),
          ] + $priceSets,
          NULL, ['onchange' => "buildAmount( this.value );"]
        );
      }
      $this->assign('hasPriceSets', $buildPriceSet);
    }

    if ($this->_action & CRM_Core_Action::DELETE) {
      $this->addButtons([
        [
          'type' => 'next',
          'name' => ts('Delete'),
          'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
          'isDefault' => TRUE,
        ],
        [
          'type' => 'cancel',
          'name' => ts('Cancel'),
        ],
      ]);
      return;
    }

    $contactField = $this->addEntityRef('contact_id', ts('Member'), ['create' => TRUE, 'api' => ['extra' => ['email']]], TRUE);
    if ($this->_context !== 'standalone') {
      $contactField->freeze();
    }

    $selOrgMemType[0][0] = $selMemTypeOrg[0] = ts('- select -');

    // Throw status bounce when no Membership type or priceset is present
    if (empty($this->allMembershipTypeDetails) && empty($priceSets)
    ) {
      CRM_Core_Error::statusBounce(ts('You do not have all the permissions needed for this page.'));
    }
    // retrieve all memberships
    $allMembershipInfo = [];
    foreach ($this->allMembershipTypeDetails as $key => $values) {
      if ($this->_mode && empty($values['minimum_fee'])) {
        continue;
      }
      else {
        $memberOfContactId = $values['member_of_contact_id'] ?? NULL;
        if (empty($selMemTypeOrg[$memberOfContactId])) {
          $selMemTypeOrg[$memberOfContactId] = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Contact',
            $memberOfContactId,
            'display_name',
            'id'
          );

          $selOrgMemType[$memberOfContactId][0] = ts('- select -');
        }
        if (empty($selOrgMemType[$memberOfContactId][$key])) {
          $selOrgMemType[$memberOfContactId][$key] = $values['name'] ?? NULL;
        }
      }
      $totalAmount = $values['minimum_fee'] ?? NULL;
      // build membership info array, which is used when membership type is selected to:
      // - set the payment information block
      // - set the max related block
      $allMembershipInfo[$key] = [
        'financial_type_id' => $values['financial_type_id'] ?? NULL,
        'total_amount' => CRM_Utils_Money::formatLocaleNumericRoundedForDefaultCurrency($totalAmount),
        'total_amount_numeric' => $totalAmount,
        'auto_renew' => $values['auto_renew'] ?? NULL,
        'tax_rate' => $values['tax_rate'],
        'has_related' => isset($values['relationship_type_id']),
        'max_related' => $values['max_related'] ?? NULL,
      ];
    }

    $this->assign('allMembershipInfo', json_encode($allMembershipInfo));

    // show organization by default, if only one organization in
    // the list
    if (count($selMemTypeOrg) == 2) {
      unset($selMemTypeOrg[0], $selOrgMemType[0][0]);
    }
    //sort membership organization and type, CRM-6099
    natcasesort($selMemTypeOrg);
    foreach ($selOrgMemType as $index => $orgMembershipType) {
      natcasesort($orgMembershipType);
      $selOrgMemType[$index] = $orgMembershipType;
    }

    $memTypeJs = [
      'onChange' => "buildMaxRelated(this.value,true); CRM.buildCustomData('Membership', this.value);",
    ];

    if (!empty($this->_recurPaymentProcessors)) {
      $memTypeJs['onChange'] = "" . $memTypeJs['onChange'] . " buildAutoRenew(this.value, null, '{$this->_mode}');";
    }

    $this->add('text', 'max_related', ts('Max related'),
      CRM_Core_DAO::getAttribute('CRM_Member_DAO_Membership', 'max_related')
    );

    $sel = &$this->addElement('hierselect',
      'membership_type_id',
      ts('Membership Organization and Type'),
      $memTypeJs
    );

    $sel->setOptions([$selMemTypeOrg, $selOrgMemType]);

    if ($this->_action & CRM_Core_Action::ADD) {
      $this->add('number', 'num_terms', ts('Number of Terms'), ['size' => 6]);
    }

    $this->add('text', 'source', ts('Source'),
      CRM_Core_DAO::getAttribute('CRM_Member_DAO_Membership', 'source')
    );

    //CRM-7362 --add campaigns.
    $campaignId = NULL;
    if ($this->_id) {
      $campaignId = CRM_Core_DAO::getFieldValue('CRM_Member_DAO_Membership', $this->_id, 'campaign_id');
    }
    CRM_Campaign_BAO_Campaign::addCampaign($this, $campaignId);

    if (!$this->_mode) {
      $this->add('select', 'status_id', ts('Membership Status'),
        ['' => ts('- select -')] + CRM_Member_PseudoConstant::membershipStatus(NULL, NULL, 'label')
      );

      $statusOverride = $this->addElement('select', 'is_override', ts('Status Override?'),
        CRM_Member_StatusOverrideTypes::getSelectOptions()
      );

      $this->add('datepicker', 'status_override_end_date', ts('Status Override End Date'), '', FALSE, ['minDate' => CRM_Utils_Time::date('Y-m-d'), 'time' => FALSE]);

      $this->addElement('checkbox', 'record_contribution', ts('Record Membership Payment?'));

      $this->add('text', 'total_amount', ts('Amount'));
      $this->addRule('total_amount', ts('Please enter a valid amount.'), 'money');

      $this->add('datepicker', 'receive_date', ts('Received'), [], FALSE, ['time' => TRUE]);

      $this->add('select', 'payment_instrument_id',
        ts('Payment Method'),
        ['' => ts('- select -')] + CRM_Contribute_PseudoConstant::paymentInstrument(),
        FALSE, ['onChange' => "return showHideByValue('payment_instrument_id','4','checkNumber','table-row','select',false);"]
      );
      $this->add('text', 'trxn_id', ts('Transaction ID'));
      $this->addRule('trxn_id', ts('Transaction ID already exists in Database.'),
        'objectExists', [
          'CRM_Contribute_DAO_Contribution',
          $this->_id,
          'trxn_id',
        ]
      );

      $this->add('select', 'contribution_status_id',
        ts('Payment Status'), CRM_Contribute_BAO_Contribution_Utils::getContributionStatuses('membership')
      );
      $this->add('text', 'check_number', ts('Check Number'),
        CRM_Core_DAO::getAttribute('CRM_Contribute_DAO_Contribution', 'check_number')
      );
    }
    else {
      //add field for amount to allow an amount to be entered that differs from minimum
      $this->add('text', 'total_amount', ts('Amount'));
    }
    $this->add('select', 'financial_type_id',
      ts('Financial Type'),
      ['' => ts('- select -')] + CRM_Financial_BAO_FinancialType::getAvailableFinancialTypes($financialTypes, $this->_action)
    );

    $this->addElement('checkbox', 'is_different_contribution_contact', ts('Record Payment from a Different Contact?'));

    $this->addSelect('soft_credit_type_id', ['entity' => 'contribution_soft']);
    $this->addEntityRef('soft_credit_contact_id', ts('Payment From'), ['create' => TRUE]);

    $this->addElement('checkbox',
      'send_receipt',
      ts('Send Confirmation and Receipt?'), NULL,
      ['onclick' => "showEmailOptions()"]
    );

    $this->add('select', 'from_email_address', ts('Receipt From'), $this->_fromEmails);

    $this->add('textarea', 'receipt_text', ts('Receipt Message'));

    // Retrieve the name and email of the contact - this will be the TO for receipt email
    if ($this->_contactID) {
      [$this->_memberDisplayName, $this->_memberEmail] = CRM_Contact_BAO_Contact_Location::getEmailDetails($this->_contactID);

      $this->assign('emailExists', $this->_memberEmail);
      $this->assign('displayName', $this->_memberDisplayName);
    }

    if ($isUpdateToExistingRecurringMembership && CRM_Member_BAO_Membership::isCancelSubscriptionSupported($this->_id)) {
      $this->assign('cancelAutoRenew',
        CRM_Utils_System::url('civicrm/contribute/unsubscribe', "reset=1&mid={$this->_id}")
      );
    }

    $this->assign('isRecur', $isUpdateToExistingRecurringMembership);

    $this->addFormRule(['CRM_Member_Form_Membership', 'formRule'], $this);
    $mailingInfo = Civi::settings()->get('mailing_backend');
    $this->assign('isEmailEnabledForSite', ($mailingInfo['outBound_option'] != 2));

    parent::buildQuickForm();
  }

  /**
   * Validation.
   *
   * @param array $params
   *   (ref.) an assoc array of name/value pairs.
   *
   * @param array $files
   * @param CRM_Member_Form_Membership $self
   *
   * @return bool|array
   *   mixed true or array of errors
   *
   * @throws \CRM_Core_Exception
   * @throws CiviCRM_API3_Exception
   */
  public static function formRule($params, $files, $self) {
    $errors = [];

    $priceSetId = $self->getPriceSetID($params);
    $priceSetDetails = $self->getPriceSetDetails($params);

    $selectedMemberships = self::getSelectedMemberships($priceSetDetails[$priceSetId], $params);

    if (!empty($params['price_set_id'])) {
      CRM_Price_BAO_PriceField::priceSetValidation($priceSetId, $params, $errors);

      $priceFieldIDS = self::getPriceFieldIDs($params, $priceSetDetails[$priceSetId]);

      if (!empty($priceFieldIDS)) {
        $ids = implode(',', $priceFieldIDS);

        $count = CRM_Price_BAO_PriceSet::getMembershipCount($ids);
        foreach ($count as $occurrence) {
          if ($occurrence > 1) {
            $errors['_qf_default'] = ts('Select at most one option associated with the same membership type.');
          }
        }
      }
      // Return error if empty $self->_memTypeSelected
      if (empty($errors) && empty($selectedMemberships)) {
        $errors['_qf_default'] = ts('Select at least one membership option.');
      }
      if (!$self->_mode && empty($params['record_contribution'])) {
        $errors['record_contribution'] = ts('Record Membership Payment is required when you use a price set.');
      }
    }
    else {
      if (empty($params['membership_type_id'][1])) {
        $errors['membership_type_id'] = ts('Please select a membership type.');
      }
      $numterms = $params['num_terms'] ?? NULL;
      if ($numterms && intval($numterms) != $numterms) {
        $errors['num_terms'] = ts('Please enter an integer for the number of terms.');
      }

      if (($self->_mode || isset($params['record_contribution'])) && empty($params['financial_type_id'])) {
        $errors['financial_type_id'] = ts('Please enter the financial Type.');
      }
    }

    if (!empty($errors) && (count($selectedMemberships) > 1)) {
      $memberOfContacts = CRM_Member_BAO_MembershipType::getMemberOfContactByMemTypes($selectedMemberships);
      $duplicateMemberOfContacts = array_count_values($memberOfContacts);
      foreach ($duplicateMemberOfContacts as $countDuplicate) {
        if ($countDuplicate > 1) {
          $errors['_qf_default'] = ts('Please do not select more than one membership associated with the same organization.');
        }
      }
    }

    if (!empty($errors)) {
      return $errors;
    }

    if (!empty($params['record_contribution']) && empty($params['payment_instrument_id'])) {
      $errors['payment_instrument_id'] = ts('Payment Method is a required field.');
    }

    if (!empty($params['is_different_contribution_contact'])) {
      if (empty($params['soft_credit_type_id'])) {
        $errors['soft_credit_type_id'] = ts('Please Select a Soft Credit Type');
      }
      if (empty($params['soft_credit_contact_id'])) {
        $errors['soft_credit_contact_id'] = ts('Please select a contact');
      }
    }

    if (!empty($params['payment_processor_id'])) {
      // validate payment instrument (e.g. credit card number)
      CRM_Core_Payment_Form::validatePaymentInstrument($params['payment_processor_id'], $params, $errors, NULL);
    }

    $joinDate = NULL;
    if (!empty($params['join_date'])) {

      $joinDate = CRM_Utils_Date::processDate($params['join_date']);

      foreach ($selectedMemberships as $memType) {
        $startDate = NULL;
        if (!empty($params['start_date'])) {
          $startDate = CRM_Utils_Date::processDate($params['start_date']);
        }

        // if end date is set, ensure that start date is also set
        // and that end date is later than start date
        $endDate = NULL;
        if (!empty($params['end_date'])) {
          $endDate = CRM_Utils_Date::processDate($params['end_date']);
        }

        $membershipDetails = CRM_Member_BAO_MembershipType::getMembershipType($memType);
        if ($startDate && CRM_Utils_Array::value('period_type', $membershipDetails) === 'rolling') {
          if ($startDate < $joinDate) {
            $errors['start_date'] = ts('Start date must be the same or later than Member since.');
          }
        }

        if ($endDate) {
          if ($membershipDetails['duration_unit'] === 'lifetime') {
            // Check if status is NOT cancelled or similar. For lifetime memberships, there is no automated
            // process to update status based on end-date. The user must change the status now.
            $result = civicrm_api3('MembershipStatus', 'get', [
              'sequential' => 1,
              'is_current_member' => 0,
            ]);
            $tmp_statuses = $result['values'];
            $status_ids = [];
            foreach ($tmp_statuses as $cur_stat) {
              $status_ids[] = $cur_stat['id'];
            }

            if (empty($params['status_id']) || in_array($params['status_id'], $status_ids) == FALSE) {
              $errors['status_id'] = ts('Please enter a status that does NOT represent a current membership status.');
            }

            if (!empty($params['is_override']) && !CRM_Member_StatusOverrideTypes::isPermanent($params['is_override'])) {
              $errors['is_override'] = ts('Because you set an End Date for a lifetime membership, This must be set to "Override Permanently"');
            }
          }
          else {
            if (!$startDate) {
              $errors['start_date'] = ts('Start date must be set if end date is set.');
            }
            if ($endDate < $startDate) {
              $errors['end_date'] = ts('End date must be the same or later than start date.');
            }
          }
        }

        // Default values for start and end dates if not supplied on the form.
        $defaultDates = CRM_Member_BAO_MembershipType::getDatesForMembershipType($memType,
          $joinDate,
          $startDate,
          $endDate
        );

        if (!$startDate) {
          $startDate = CRM_Utils_Array::value('start_date',
            $defaultDates
          );
        }
        if (!$endDate) {
          $endDate = CRM_Utils_Array::value('end_date',
            $defaultDates
          );
        }

        //CRM-3724, check for availability of valid membership status.
        if ((empty($params['is_override']) || CRM_Member_StatusOverrideTypes::isNo($params['is_override'])) && !isset($errors['_qf_default'])) {
          $calcStatus = CRM_Member_BAO_MembershipStatus::getMembershipStatusByDate($startDate,
            $endDate,
            $joinDate,
            'now',
            TRUE,
            $memType,
            $params
          );
          if (empty($calcStatus)) {
            $url = CRM_Utils_System::url('civicrm/admin/member/membershipStatus', 'reset=1&action=browse');
            $errors['_qf_default'] = ts('There is no valid Membership Status available for selected membership dates.');
            $status = ts('Oops, it looks like there is no valid membership status available for the given membership dates. You can <a href="%1">Configure Membership Status Rules</a>.', [1 => $url]);
            if (!$self->_mode) {
              $status .= ' ' . ts('OR You can sign up by setting Status Override? to something other than "NO".');
            }
            CRM_Core_Session::setStatus($status, ts('Membership Status Error'), 'error');
          }
        }
      }
    }
    else {
      $errors['join_date'] = ts('Please enter the Member Since.');
    }

    if (!empty($params['is_override']) && CRM_Member_StatusOverrideTypes::isOverridden($params['is_override']) && empty($params['status_id'])) {
      $errors['status_id'] = ts('Please enter the Membership status.');
    }

    if (!empty($params['is_override']) && CRM_Member_StatusOverrideTypes::isUntilDate($params['is_override'])) {
      if (empty($params['status_override_end_date'])) {
        $errors['status_override_end_date'] = ts('Please enter the Membership override end date.');
      }
    }

    //total amount condition arise when membership type having no
    //minimum fee
    if (isset($params['record_contribution'])) {
      if (CRM_Utils_System::isNull($params['total_amount'])) {
        $errors['total_amount'] = ts('Please enter the contribution.');
      }
    }

    return empty($errors) ? TRUE : $errors;
  }

  /**
   * Process the form submission.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function postProcess() {
    if ($this->_action & CRM_Core_Action::DELETE) {
      CRM_Member_BAO_Membership::del($this->_id);
      return;
    }
    // get the submitted form values.
    $this->_params = $this->controller->exportValues($this->_name);
    $this->prepareStatusOverrideValues();

    $this->submit();

    $this->setUserContext();
  }

  /**
   * Prepares the values related to status override.
   */
  private function prepareStatusOverrideValues() {
    $this->setOverrideDateValue();
    $this->convertIsOverrideValue();
  }

  /**
   * Sets status override end date to empty value if
   * the selected override option is not 'until date'.
   */
  private function setOverrideDateValue() {
    if (!CRM_Member_StatusOverrideTypes::isUntilDate(CRM_Utils_Array::value('is_override', $this->_params))) {
      $this->_params['status_override_end_date'] = '';
    }
  }

  /**
   * Convert the value of selected (status override?)
   * option to TRUE if it indicate an overridden status
   * or FALSE otherwise.
   */
  private function convertIsOverrideValue() {
    $this->_params['is_override'] = CRM_Member_StatusOverrideTypes::isOverridden($this->_params['is_override'] ?? CRM_Member_StatusOverrideTypes::NO);
  }

  /**
   * Send email receipt.
   *
   * @param CRM_Core_Form $form
   *   Form object.
   * @param array $formValues
   * @param object $membership
   *   Object.
   *
   * @return bool
   *   true if mail was sent successfully
   * @throws \CRM_Core_Exception
   *
   * @deprecated
   *   This function is shared with Batch_Entry which has limited overlap
   *   & needs rationalising.
   *
   */
  public static function emailReceipt($form, &$formValues, $membership) {
    // retrieve 'from email id' for acknowledgement
    $receiptFrom = $formValues['from_email_address'] ?? NULL;

    // @todo figure out how much of the stuff below is genuinely shared with the batch form & a logical shared place.
    if (!empty($formValues['payment_instrument_id'])) {
      $paymentInstrument = CRM_Contribute_PseudoConstant::paymentInstrument();
      $formValues['paidBy'] = $paymentInstrument[$formValues['payment_instrument_id']];
    }

    $form->assign('module', 'Membership');
    $form->assign('contactID', $formValues['contact_id']);

    $form->assign('membershipID', CRM_Utils_Array::value('membership_id', $form->_params, CRM_Utils_Array::value('membership_id', $form->_defaultValues)));

    if (!empty($formValues['contribution_id'])) {
      $form->assign('contributionID', $formValues['contribution_id']);
    }

    if (!empty($formValues['contribution_status_id'])) {
      $form->assign('contributionStatusID', $formValues['contribution_status_id']);
      $form->assign('contributionStatus', CRM_Contribute_PseudoConstant::contributionStatus($formValues['contribution_status_id'], 'name'));
    }

    if (!empty($formValues['is_renew'])) {
      $form->assign('receiptType', 'membership renewal');
    }
    else {
      $form->assign('receiptType', 'membership signup');
    }
    $form->assign('receive_date', CRM_Utils_Array::value('receive_date', $formValues));
    $form->assign('formValues', $formValues);

    $form->assign('mem_start_date', CRM_Utils_Date::formatDateOnlyLong($membership->start_date));
    if (!CRM_Utils_System::isNull($membership->end_date)) {
      $form->assign('mem_end_date', CRM_Utils_Date::formatDateOnlyLong($membership->end_date));
    }
    $form->assign('membership_name', CRM_Member_PseudoConstant::membershipType($membership->membership_type_id));

    // @todo - if we have to figure out if this is for batch processing it doesn't belong in the shared function.
    $isBatchProcess = is_a($form, 'CRM_Batch_Form_Entry');
    if ((empty($form->_contributorDisplayName) || empty($form->_contributorEmail)) || $isBatchProcess) {
      // in this case the form is being called statically from the batch editing screen
      // having one class in the form layer call another statically is not greate
      // & we should aim to move this function to the BAO layer in future.
      // however, we can assume that the contact_id passed in by the batch
      // function will be the recipient
      [$form->_contributorDisplayName, $form->_contributorEmail]
        = CRM_Contact_BAO_Contact_Location::getEmailDetails($formValues['contact_id']);
      if (empty($form->_receiptContactId) || $isBatchProcess) {
        $form->_receiptContactId = $formValues['contact_id'];
      }
    }

    CRM_Core_BAO_MessageTemplate::sendTemplate(
      [
        'groupName' => 'msg_tpl_workflow_membership',
        'valueName' => 'membership_offline_receipt',
        'contactId' => $form->_receiptContactId,
        'from' => $receiptFrom,
        'toName' => $form->_contributorDisplayName,
        'toEmail' => $form->_contributorEmail,
        'PDFFilename' => ts('receipt') . '.pdf',
        'isEmailPdf' => Civi::settings()->get('invoicing') && Civi::settings()->get('invoice_is_email_pdf'),
        'contributionId' => $formValues['contribution_id'],
        'isTest' => (bool) ($form->_action & CRM_Core_Action::PREVIEW),
      ]
    );

    return TRUE;
  }

  /**
   * Submit function.
   *
   * This is also accessed by unit tests.
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function submit(): void {
    $this->storeContactFields($this->_params);
    $this->beginPostProcess();
    $endDate = NULL;
    $membership = $calcDate = [];

    $paymentInstrumentID = $this->_paymentProcessor['object']->getPaymentInstrumentID();
    $params = $softParams = $ids = [];

    $mailSend = FALSE;
    $this->processBillingAddress();
    $formValues = $this->_params;
    $formValues = $this->setPriceSetParameters($formValues);

    if ($this->_id) {
      $ids['membership'] = $params['id'] = $this->_id;
    }

    // Set variables that we normally get from context.
    // In form mode these are set in preProcess.
    //TODO: set memberships, fixme
    $this->setContextVariables($formValues);

    $this->_memTypeSelected = self::getSelectedMemberships(
      $this->_priceSet,
      $formValues
    );
    $formValues['financial_type_id'] = $this->getFinancialTypeID();
    $membershipTypeValues = [];
    foreach ($this->_memTypeSelected as $memType) {
      $membershipTypeValues[$memType]['membership_type_id'] = $memType;
    }

    //take the required membership recur values.
    if ($this->_mode && !empty($formValues['auto_renew'])) {
      $params['is_recur'] = $formValues['is_recur'] = TRUE;

      $count = 0;
      foreach ($this->_memTypeSelected as $memType) {
        $recurMembershipTypeValues = CRM_Utils_Array::value($memType,
          $this->allMembershipTypeDetails, []
        );
        if (!$recurMembershipTypeValues['auto_renew']) {
          continue;
        }
        foreach ([
          'frequency_interval' => 'duration_interval',
          'frequency_unit' => 'duration_unit',
        ] as $mapVal => $mapParam) {
          $membershipTypeValues[$memType][$mapVal] = $recurMembershipTypeValues[$mapParam];

          if (!$count) {
            $formValues[$mapVal] = CRM_Utils_Array::value($mapParam,
              $recurMembershipTypeValues
            );
          }
        }
        $count++;
      }
    }

    $isQuickConfig = $this->_priceSet['is_quick_config'];

    $termsByType = [];

    $lineItem = [$this->order->getPriceSetID() => $this->order->getLineItems()];

    $params['tax_amount'] = $this->order->getTotalTaxAmount();
    $params['total_amount'] = $this->order->getTotalAmount();
    if (!empty($lineItem[$this->_priceSetId])) {
      foreach ($lineItem[$this->_priceSetId] as &$li) {
        if (!empty($li['membership_type_id'])) {
          if (!empty($li['membership_num_terms'])) {
            $termsByType[$li['membership_type_id']] = $li['membership_num_terms'];
          }
        }
      }
    }

    $params['contact_id'] = $this->_contactID;

    $fields = [
      'status_id',
      'source',
      'is_override',
      'status_override_end_date',
      'campaign_id',
    ];

    foreach ($fields as $f) {
      $params[$f] = $formValues[$f] ?? NULL;
    }

    // fix for CRM-3724
    // when is_override false ignore is_admin statuses during membership
    // status calculation. similarly we did fix for import in CRM-3570.
    if (empty($params['is_override'])) {
      $params['exclude_is_admin'] = TRUE;
    }

    $joinDate = $formValues['join_date'];
    $startDate = $formValues['start_date'];
    $endDate = $formValues['end_date'];

    $memTypeNumTerms = empty($termsByType) ? CRM_Utils_Array::value('num_terms', $formValues) : NULL;

    $calcDates = [];
    foreach ($this->_memTypeSelected as $memType) {
      if (empty($memTypeNumTerms)) {
        $memTypeNumTerms = CRM_Utils_Array::value($memType, $termsByType, 1);
      }
      $calcDates[$memType] = CRM_Member_BAO_MembershipType::getDatesForMembershipType($memType,
        $joinDate, $startDate, $endDate, $memTypeNumTerms
      );
    }

    foreach ($calcDates as $memType => $calcDate) {
      foreach (['join_date', 'start_date', 'end_date'] as $d) {
        //first give priority to form values then calDates.
        $date = $formValues[$d] ?? NULL;
        if (!$date) {
          $date = $calcDate[$d] ?? NULL;
        }

        $membershipTypeValues[$memType][$d] = CRM_Utils_Date::processDate($date);
      }
    }

    foreach ($this->_memTypeSelected as $memType) {
      if (array_key_exists('max_related', $formValues)) {
        // max related memberships - take from form or inherit from membership type
        $membershipTypeValues[$memType]['max_related'] = $formValues['max_related'] ?? NULL;
      }
      $membershipTypeValues[$memType]['custom'] = CRM_Core_BAO_CustomField::postProcess($formValues,
        $this->_id,
        'Membership'
      );
    }

    // Retrieve the name and email of the current user - this will be the FROM for the receipt email
    [$userName] = CRM_Contact_BAO_Contact_Location::getEmailDetails(CRM_Core_Session::getLoggedInContactID());

    //CRM-13981, allow different person as a soft-contributor of chosen type
    if ($this->_contributorContactID != $this->_contactID) {
      $params['contribution_contact_id'] = $this->_contributorContactID;
      if (!empty($formValues['soft_credit_type_id'])) {
        $softParams['soft_credit_type_id'] = $formValues['soft_credit_type_id'];
        $softParams['contact_id'] = $this->_contactID;
      }
    }

    $pendingMembershipStatusId = CRM_Core_PseudoConstant::getKey('CRM_Member_BAO_Membership', 'status_id', 'Pending');

    if (!empty($formValues['record_contribution'])) {
      $recordContribution = [
        'total_amount',
        'payment_instrument_id',
        'trxn_id',
        'contribution_status_id',
        'check_number',
        'campaign_id',
        'receive_date',
        'card_type_id',
        'pan_truncation',
      ];

      foreach ($recordContribution as $f) {
        $params[$f] = $formValues[$f] ?? NULL;
      }
      $params['financial_type_id'] = $this->getFinancialTypeID();

      if (empty($formValues['source'])) {
        $params['contribution_source'] = ts('%1 Membership: Offline signup (by %2)', [
          1 => $this->getSelectedMembershipLabels(),
          2 => $userName,
        ]);
      }
      else {
        $params['contribution_source'] = $formValues['source'];
      }

      $completedContributionStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
      if (empty($params['is_override']) &&
        CRM_Utils_Array::value('contribution_status_id', $params) != $completedContributionStatusId
      ) {
        $params['status_id'] = $pendingMembershipStatusId;
        $params['skipStatusCal'] = TRUE;
        $params['is_pay_later'] = 1;
        $this->assign('is_pay_later', 1);
      }

      if ($this->getSubmittedValue('send_receipt')) {
        $params['receipt_date'] = $formValues['receive_date'] ?? NULL;
      }

      //insert financial type name in receipt.
      $formValues['contributionType_name'] = CRM_Core_DAO::getFieldValue('CRM_Financial_DAO_FinancialType',
        $this->getFinancialTypeID()
      );
    }

    // process line items, until no previous line items.
    if (!empty($lineItem)) {
      $params['lineItems'] = $lineItem;
      $params['processPriceSet'] = TRUE;
    }
    $createdMemberships = [];
    if ($this->_mode) {
      $params['total_amount'] = CRM_Utils_Array::value('total_amount', $formValues, 0);

      //CRM-20264 : Store CC type and number (last 4 digit) during backoffice or online payment
      $params['card_type_id'] = $this->_params['card_type_id'] ?? NULL;
      $params['pan_truncation'] = $this->_params['pan_truncation'] ?? NULL;
      $params['financial_type_id'] = $this->getFinancialTypeID();

      //get the payment processor id as per mode. Try removing in favour of beginPostProcess.
      $params['payment_processor_id'] = $formValues['payment_processor_id'] = $this->_paymentProcessor['id'];
      $params['register_date'] = CRM_Utils_Time::date('YmdHis');

      // add all the additional payment params we need
      $formValues['amount'] = $params['total_amount'];
      $formValues['currencyID'] = $this->getCurrency();
      $formValues['description'] = ts("Contribution submitted by a staff person using member's credit card for signup");
      $formValues['invoiceID'] = $this->getInvoiceID();
      $formValues['financial_type_id'] = $this->getFinancialTypeID();

      // at this point we've created a contact and stored its address etc
      // all the payment processors expect the name and address to be in the
      // so we copy stuff over to first_name etc.
      $paymentParams = $formValues;
      $paymentParams['contactID'] = $this->_contributorContactID;
      //CRM-10377 if payment is by an alternate contact then we need to set that person
      // as the contact in the payment params
      if ($this->_contributorContactID != $this->_contactID) {
        if (!empty($formValues['soft_credit_type_id'])) {
          $softParams['contact_id'] = $params['contact_id'];
          $softParams['soft_credit_type_id'] = $formValues['soft_credit_type_id'];
        }
      }
      if ($this->getSubmittedValue('send_receipt')) {
        $paymentParams['email'] = $this->_contributorEmail;
      }

      // This is a candidate for shared beginPostProcess function.
      // @todo Do we need this now we have $this->formatParamsForPaymentProcessor() ?
      CRM_Core_Payment_Form::mapParams($this->_bltID, $formValues, $paymentParams, TRUE);
      // CRM-7137 -for recurring membership,
      // we do need contribution and recurring records.
      $result = NULL;
      if (!empty($paymentParams['is_recur'])) {
        $this->_params = $formValues;

        $contribution = $this->processContribution(
          $paymentParams,
          [
            'contact_id' => $this->_contributorContactID,
            'line_item' => $lineItem,
            'is_test' => $this->isTest(),
            'campaign_id' => $paymentParams['campaign_id'] ?? NULL,
            'source' => CRM_Utils_Array::value('source', $paymentParams, CRM_Utils_Array::value('description', $paymentParams)),
            'payment_instrument_id' => $paymentInstrumentID,
            'financial_type_id' => $this->getFinancialTypeID(),
            'receive_date' => CRM_Utils_Time::date('YmdHis'),
            'tax_amount' => $params['tax_amount'] ?? NULL,
            'total_amount' => $this->order->getTotalAmount(),
            'invoice_id' => $this->getInvoiceID(),
            'currency' => $this->getCurrency(),
            'is_pay_later' => $params['is_pay_later'] ?? 0,
            'skipLineItem' => $params['skipLineItem'] ?? 0,
            'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending'),
            'receipt_date' => $this->getSubmittedValue('send_receipt') ? date('YmdHis') : NULL,
          ]
        );

        //create new soft-credit record, CRM-13981
        if ($softParams) {
          $softParams['contribution_id'] = $contribution->id;
          $softParams['currency'] = $contribution->currency;
          $softParams['amount'] = $contribution->total_amount;
          CRM_Contribute_BAO_ContributionSoft::add($softParams);
        }

        $paymentParams['contactID'] = $this->_contactID;
        $paymentParams['contributionID'] = $contribution->id;
        $paymentParams['contributionRecurID'] = $contribution->contribution_recur_id;
        $params['contribution_id'] = $paymentParams['contributionID'];
        $params['contribution_recur_id'] = $paymentParams['contributionRecurID'];
      }
      $paymentStatus = NULL;

      if ($params['total_amount'] > 0.0) {
        $payment = $this->_paymentProcessor['object'];
        try {
          $result = $payment->doPayment($paymentParams);
          $formValues = array_merge($formValues, $result);
          $paymentStatus = CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'contribution_status_id', $formValues['payment_status_id']);
          // Assign amount to template if payment was successful.
          $this->assign('amount', $params['total_amount']);
        }
        catch (\Civi\Payment\Exception\PaymentProcessorException $e) {
          if (!empty($paymentParams['contributionID'])) {
            CRM_Contribute_BAO_Contribution::failPayment($paymentParams['contributionID'], $this->_contactID,
              $e->getMessage());
          }
          if (!empty($paymentParams['contributionRecurID'])) {
            CRM_Contribute_BAO_ContributionRecur::deleteRecurContribution($paymentParams['contributionRecurID']);
          }

          CRM_Core_Session::singleton()->setStatus($e->getMessage());
          CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/contact/view/membership',
            "reset=1&action=add&cid={$this->_contactID}&context=membership&mode={$this->_mode}"
          ));

        }
      }

      if ($paymentStatus !== 'Completed') {
        $params['status_id'] = $pendingMembershipStatusId;
        $params['skipStatusCal'] = TRUE;
        //as membership is pending set dates to null.
        foreach ($this->_memTypeSelected as $memType) {
          $membershipTypeValues[$memType]['joinDate'] = NULL;
          $membershipTypeValues[$memType]['startDate'] = NULL;
          $membershipTypeValues[$memType]['endDate'] = NULL;
        }
        $endDate = $startDate = NULL;
      }
      $now = CRM_Utils_Time::date('YmdHis');
      $params['receive_date'] = CRM_Utils_Time::date('Y-m-d H:i:s');
      $params['invoice_id'] = $this->getInvoiceID();
      $params['contribution_source'] = ts('%1 Membership Signup: Credit card or direct debit (by %2)',
        [1 => $this->getSelectedMembershipLabels(), 2 => $userName]
      );
      $params['source'] = $formValues['source'] ?: $params['contribution_source'];
      $params['trxn_id'] = $result['trxn_id'] ?? NULL;
      $params['is_test'] = $this->isTest();
      $params['receipt_date'] = NULL;
      if ($this->getSubmittedValue('send_receipt') && $paymentStatus === 'Completed') {
        // @todo this should be updated by the send function once sent rather than
        // set here.
        $params['receipt_date'] = $now;
      }

      $this->set('params', $formValues);
      $this->assign('trxn_id', CRM_Utils_Array::value('trxn_id', $result));
      $this->assign('receive_date',
        CRM_Utils_Date::mysqlToIso($params['receive_date'])
      );

      // required for creating membership for related contacts
      $params['action'] = $this->_action;

      //create membership record.
      $count = 0;
      foreach ($this->_memTypeSelected as $memType) {
        if ($count &&
          ($relateContribution = CRM_Member_BAO_Membership::getMembershipContributionId($membership->id))
        ) {
          $membershipTypeValues[$memType]['relate_contribution_id'] = $relateContribution;
        }

        $membershipParams = array_merge($membershipTypeValues[$memType], $params);
        //CRM-15366
        if (!empty($softParams) && empty($paymentParams['is_recur'])) {
          $membershipParams['soft_credit'] = $softParams;
        }
        if (isset($result['fee_amount'])) {
          $membershipParams['fee_amount'] = $result['fee_amount'];
        }
        // This is required to trigger the recording of the membership contribution in the
        // CRM_Member_BAO_Membership::Create function.
        // @todo stop setting this & 'teach' the create function to respond to something
        // appropriate as part of our 2-step always create the pending contribution & then finally add the payment
        // process -
        // @see http://wiki.civicrm.org/confluence/pages/viewpage.action?pageId=261062657#Payments&AccountsRoadmap-Movetowardsalwaysusinga2-steppaymentprocess
        $membershipParams['contribution_status_id'] = $result['payment_status_id'] ?? NULL;
        if (!empty($paymentParams['is_recur'])) {
          // The earlier process created the line items (although we want to get rid of the earlier one in favour
          // of a single path!
          unset($membershipParams['lineItems']);
        }
        $membershipParams['payment_instrument_id'] = $paymentInstrumentID;
        // @todo stop passing $ids (membership and userId only are set above)
        $membership = CRM_Member_BAO_Membership::create($membershipParams, $ids);
        $params['contribution'] = $membershipParams['contribution'] ?? NULL;
        unset($params['lineItems']);
        $this->_membershipIDs[] = $membership->id;
        $createdMemberships[$memType] = $membership;
        $count++;
      }

    }
    else {
      $params['action'] = $this->_action;
      foreach ($lineItem[$this->_priceSetId] as $id => $lineItemValues) {
        if (empty($lineItemValues['membership_type_id'])) {
          continue;
        }

        // @todo figure out why recieve_date isn't being set right here.
        if (empty($params['receive_date'])) {
          $params['receive_date'] = CRM_Utils_Time::date('Y-m-d H:i:s');
        }
        $membershipParams = array_merge($params, $membershipTypeValues[$lineItemValues['membership_type_id']]);

        if (!empty($softParams)) {
          $membershipParams['soft_credit'] = $softParams;
        }
        unset($membershipParams['contribution_status_id']);
        $membershipParams['skipLineItem'] = TRUE;
        unset($membershipParams['lineItems']);
        // @todo stop passing $ids (membership and userId only are set above)
        $membership = CRM_Member_BAO_Membership::create($membershipParams, $ids);
        $lineItem[$this->_priceSetId][$id]['entity_id'] = $membership->id;
        $lineItem[$this->_priceSetId][$id]['entity_table'] = 'civicrm_membership';

        $this->_membershipIDs[] = $membership->id;
        $createdMemberships[$membership->membership_type_id] = $membership;
      }
      $params['lineItems'] = $lineItem;
      if (!empty($formValues['record_contribution'])) {
        CRM_Member_BAO_Membership::recordMembershipContribution($params);
      }
    }
    $isRecur = $params['is_recur'] ?? NULL;
    if (($this->_action & CRM_Core_Action::UPDATE)) {
      $this->addStatusMessage($this->getStatusMessageForUpdate($membership, $endDate));
    }
    elseif (($this->_action & CRM_Core_Action::ADD)) {
      $this->addStatusMessage($this->getStatusMessageForCreate($endDate, $createdMemberships,
        $isRecur, $calcDates));
    }

    // This would always be true as we always add price set id into both
    // quick config & non quick config price sets.
    if (!empty($lineItem[$this->_priceSetId])) {
      $invoicing = Civi::settings()->get('invoicing');
      $taxAmount = FALSE;
      $totalTaxAmount = 0;
      foreach ($lineItem[$this->_priceSetId] as & $priceFieldOp) {
        if (!empty($priceFieldOp['membership_type_id'])) {
          $priceFieldOp['start_date'] = $membershipTypeValues[$priceFieldOp['membership_type_id']]['start_date'] ? CRM_Utils_Date::formatDateOnlyLong($membershipTypeValues[$priceFieldOp['membership_type_id']]['start_date']) : '-';
          $priceFieldOp['end_date'] = $membershipTypeValues[$priceFieldOp['membership_type_id']]['end_date'] ? CRM_Utils_Date::formatDateOnlyLong($membershipTypeValues[$priceFieldOp['membership_type_id']]['end_date']) : '-';
        }
        else {
          $priceFieldOp['start_date'] = $priceFieldOp['end_date'] = 'N/A';
        }
        if ($invoicing && isset($priceFieldOp['tax_amount'])) {
          $taxAmount = TRUE;
          $totalTaxAmount += $priceFieldOp['tax_amount'];
        }
      }
      if ($invoicing) {
        $dataArray = [];
        foreach ($lineItem[$this->_priceSetId] as $key => $value) {
          if (isset($value['tax_amount']) && isset($value['tax_rate'])) {
            if (isset($dataArray[$value['tax_rate']])) {
              $dataArray[$value['tax_rate']] = $dataArray[$value['tax_rate']] + CRM_Utils_Array::value('tax_amount', $value);
            }
            else {
              $dataArray[$value['tax_rate']] = $value['tax_amount'] ?? NULL;
            }
          }
        }
        if ($taxAmount) {
          $this->assign('totalTaxAmount', $totalTaxAmount);
          // Not sure why would need this on Submit.... unless it's being used when sending mails in which case this is the wrong place
          $this->assign('taxTerm', $this->getSalesTaxTerm());
        }
        $this->assign('dataArray', $dataArray);
      }
    }
    $this->assign('lineItem', !empty($lineItem) && !$isQuickConfig ? $lineItem : FALSE);

    $receiptSend = FALSE;
    $contributionId = CRM_Member_BAO_Membership::getMembershipContributionId($membership->id);
    $membershipIds = $this->_membershipIDs;
    if ($contributionId && !empty($membershipIds)) {
      $contributionDetails = CRM_Contribute_BAO_Contribution::getContributionDetails(
        CRM_Export_Form_Select::MEMBER_EXPORT, $this->_membershipIDs);
      if ($contributionDetails[$membership->id]['contribution_status'] === 'Completed') {
        $receiptSend = TRUE;
      }
    }

    $receiptSent = FALSE;
    if ($this->getSubmittedValue('send_receipt') && $receiptSend) {
      $formValues['contact_id'] = $this->_contactID;
      $formValues['contribution_id'] = $contributionId;
      // We really don't need a distinct receipt_text_signup vs receipt_text_renewal as they are
      // handled in the receipt. But by setting one we avoid breaking templates for now
      // although at some point we should switch in the templates.
      $formValues['receipt_text_signup'] = $formValues['receipt_text'];
      // send email receipt
      $this->assignBillingName();
      $mailSend = $this->emailMembershipReceipt($formValues, $membership);
      $receiptSent = TRUE;
    }

    // finally set membership id if already not set
    if (!$this->_id) {
      $this->_id = $membership->id;
    }

    $this->updateContributionOnMembershipTypeChange($params, $membership);
    if ($receiptSent && $mailSend) {
      $this->addStatusMessage(ts('A membership confirmation and receipt has been sent to %1.', [1 => $this->_contributorEmail]));
    }

    CRM_Core_Session::setStatus($this->getStatusMessage(), ts('Complete'), 'success');
    $this->setStatusMessage($membership);
  }

  /**
   * Update related contribution of a membership if update_contribution_on_membership_type_change
   *   contribution setting is enabled and type is changed on edit
   *
   * @param array $inputParams
   *      submitted form values
   * @param CRM_Member_DAO_Membership $membership
   *     Updated membership object
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  protected function updateContributionOnMembershipTypeChange($inputParams, $membership) {
    if (Civi::settings()->get('update_contribution_on_membership_type_change') &&
    // on update
      ($this->_action & CRM_Core_Action::UPDATE) &&
    // if ID is present
      $this->_id &&
    // if selected membership doesn't match with earlier membership
      !in_array($this->_memType, $this->_memTypeSelected)
    ) {
      if (!empty($inputParams['is_recur'])) {
        CRM_Core_Session::setStatus(ts('Associated recurring contribution cannot be updated on membership type change.', ts('Error'), 'error'));
        return;
      }

      // fetch lineitems by updated membership ID
      $lineItems = CRM_Price_BAO_LineItem::getLineItems($membership->id, 'membership');
      // retrieve the related contribution ID
      $contributionID = CRM_Core_DAO::getFieldValue(
        'CRM_Member_DAO_MembershipPayment',
        $membership->id,
        'contribution_id',
        'membership_id'
      );
      // get price fields of chosen price-set
      $priceSetDetails = CRM_Utils_Array::value(
        $this->_priceSetId,
        CRM_Price_BAO_PriceSet::getSetDetail(
          $this->_priceSetId,
          TRUE,
          TRUE
        )
      );

      // add price field information in $inputParams
      self::addPriceFieldByMembershipType($inputParams, $priceSetDetails['fields'], $membership->membership_type_id);

      // update related contribution and financial records
      CRM_Price_BAO_LineItem::changeFeeSelections(
        $inputParams,
        $membership->id,
        'membership',
        $contributionID,
        $priceSetDetails['fields'],
        $lineItems
      );
      CRM_Core_Session::setStatus(ts('Associated contribution is updated on membership type change.'), ts('Success'), 'success');
    }
  }

  /**
   * Add selected price field information in $formValues
   *
   * @param array $formValues
   *      submitted form values
   * @param array $priceFields
   *     Price fields of selected Priceset ID
   * @param int $membershipTypeID
   *     Selected membership type ID
   *
   */
  public static function addPriceFieldByMembershipType(&$formValues, $priceFields, $membershipTypeID) {
    foreach ($priceFields as $priceFieldID => $priceField) {
      if (isset($priceField['options']) && count($priceField['options'])) {
        foreach ($priceField['options'] as $option) {
          if ($option['membership_type_id'] == $membershipTypeID) {
            $formValues["price_{$priceFieldID}"] = $option['id'];
            break;
          }
        }
      }
    }
  }

  /**
   * Set context in session.
   */
  protected function setUserContext() {
    $buttonName = $this->controller->getButtonName();
    $session = CRM_Core_Session::singleton();

    if ($buttonName == $this->getButtonName('upload', 'new')) {
      if ($this->_context === 'standalone') {
        $url = CRM_Utils_System::url('civicrm/member/add',
          'reset=1&action=add&context=standalone'
        );
      }
      else {
        $url = CRM_Utils_System::url('civicrm/contact/view/membership',
          "reset=1&action=add&context=membership&cid={$this->_contactID}"
        );
      }
    }
    else {
      $url = CRM_Utils_System::url('civicrm/contact/view',
        "reset=1&cid={$this->_contactID}&selectedChild=member"
      );
    }
    $session->replaceUserContext($url);
  }

  /**
   * Get status message for updating membership.
   *
   * @param CRM_Member_BAO_Membership $membership
   * @param string $endDate
   *
   * @return string
   */
  protected function getStatusMessageForUpdate($membership, $endDate) {
    // End date can be modified by hooks, so if end date is set then use it.
    $endDate = ($membership->end_date) ? $membership->end_date : $endDate;

    $statusMsg = ts('Membership for %1 has been updated.', [1 => $this->_memberDisplayName]);
    if ($endDate && $endDate !== 'null') {
      $endDate = CRM_Utils_Date::customFormat($endDate);
      $statusMsg .= ' ' . ts('The membership End Date is %1.', [1 => $endDate]);
    }
    return $statusMsg;
  }

  /**
   * Get status message for create action.
   *
   * @param string $endDate
   * @param array $createdMemberships
   * @param bool $isRecur
   * @param array $calcDates
   *
   * @return array|string
   */
  protected function getStatusMessageForCreate($endDate, $createdMemberships,
                                               $isRecur, $calcDates) {
    // FIX ME: fix status messages

    $statusMsg = [];
    foreach ($this->_memTypeSelected as $membershipTypeID) {
      $statusMsg[$membershipTypeID] = ts('%1 membership for %2 has been added.', [
        1 => $this->allMembershipTypeDetails[$membershipTypeID]['name'],
        2 => $this->_memberDisplayName,
      ]);

      $membership = $createdMemberships[$membershipTypeID];
      $memEndDate = $membership->end_date ?: $endDate;

      //get the end date from calculated dates.
      if (!$memEndDate && !$isRecur) {
        $memEndDate = $calcDates[$membershipTypeID]['end_date'] ?? NULL;
      }

      if ($memEndDate && $memEndDate !== 'null') {
        $memEndDate = CRM_Utils_Date::formatDateOnlyLong($memEndDate);
        $statusMsg[$membershipTypeID] .= ' ' . ts('The new membership End Date is %1.', [1 => $memEndDate]);
      }
    }
    $statusMsg = implode('<br/>', $statusMsg);
    return $statusMsg;
  }

  /**
   * @param $membership
   */
  protected function setStatusMessage($membership) {
    //CRM-15187
    // display message when membership type is changed
    if (($this->_action & CRM_Core_Action::UPDATE) && $this->_id && !in_array($this->_memType, $this->_memTypeSelected)) {
      $lineItem = CRM_Price_BAO_LineItem::getLineItems($this->_id, 'membership');
      $maxID = max(array_keys($lineItem));
      $lineItem = $lineItem[$maxID];
      $membershipTypeDetails = $this->allMembershipTypeDetails[$membership->membership_type_id];
      if ($membershipTypeDetails['financial_type_id'] != $lineItem['financial_type_id']) {
        CRM_Core_Session::setStatus(
          ts('The financial types associated with the old and new membership types are different. You may want to edit the contribution associated with this membership to adjust its financial type.'),
          ts('Warning')
        );
      }
      if ($membershipTypeDetails['minimum_fee'] != $lineItem['line_total']) {
        CRM_Core_Session::setStatus(
          ts('The cost of the old and new membership types are different. You may want to edit the contribution associated with this membership to adjust its amount.'),
          ts('Warning')
        );
      }
    }
  }

  /**
   * @return bool
   * @throws \CRM_Core_Exception
   */
  protected function isUpdateToExistingRecurringMembership() {
    $isRecur = FALSE;
    if ($this->_action & CRM_Core_Action::UPDATE
      && CRM_Core_DAO::getFieldValue('CRM_Member_DAO_Membership', $this->getEntityId(),
        'contribution_recur_id')
      && !CRM_Member_BAO_Membership::isSubscriptionCancelled($this->getEntityId())) {

      $isRecur = TRUE;
    }
    return $isRecur;
  }

  /**
   * Send a receipt for the membership.
   *
   * @param array $formValues
   * @param \CRM_Member_BAO_Membership $membership
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  protected function emailMembershipReceipt($formValues, $membership) {
    $customValues = $this->getCustomValuesForReceipt($formValues, $membership);
    $this->assign('customValues', $customValues);

    if ($this->_mode) {
      // @todo move this outside shared code as Batch entry just doesn't
      $this->assign('address', CRM_Utils_Address::getFormattedBillingAddressFieldsFromParameters(
        $this->_params,
        $this->_bltID
      ));

      $valuesForForm = CRM_Contribute_Form_AbstractEditPayment::formatCreditCardDetails($this->_params);
      $this->assignVariables($valuesForForm, ['credit_card_exp_date', 'credit_card_type', 'credit_card_number']);
      $this->assign('is_pay_later', 0);
      $this->assign('isPrimary', 1);
    }
    return self::emailReceipt($this, $formValues, $membership);
  }

  /**
   * Filter the custom values from the input parameters (for display in the email).
   *
   * @todo figure out why the scary code this calls does & document.
   *
   * @param array $formValues
   * @param \CRM_Member_BAO_Membership $membership
   * @return array
   */
  protected function getCustomValuesForReceipt($formValues, $membership) {
    $customFields = $customValues = [];
    if (property_exists($this, '_groupTree')
      && !empty($this->_groupTree)
    ) {
      foreach ($this->_groupTree as $groupID => $group) {
        if ($groupID === 'info') {
          continue;
        }
        foreach ($group['fields'] as $k => $field) {
          $field['title'] = $field['label'];
          $customFields["custom_{$k}"] = $field;
        }
      }
    }

    $members = [['member_id', '=', $membership->id, 0, 0]];
    // check whether its a test drive
    if ($this->_mode === 'test') {
      $members[] = ['member_test', '=', 1, 0, 0];
    }

    CRM_Core_BAO_UFGroup::getValues($formValues['contact_id'], $customFields, $customValues, FALSE, $members);
    return $customValues;
  }

  /**
   * Get the selected memberships as a string of labels.
   *
   * @return string
   */
  protected function getSelectedMembershipLabels(): string {
    $return = [];
    foreach ($this->_memTypeSelected as $membershipTypeID) {
      $return[] = $this->allMembershipTypeDetails[$membershipTypeID]['name'];
    }
    return implode(', ', $return);
  }

  /**
   * Legacy contribution processing function.
   *
   * This is copied from a shared function in order to clean it up. Most of the
   * stuff in it, maybe all except the ContributionRecur create is
   * not applicable to this form & can be removed in follow up cleanup.
   *
   * It's like the contribution create being done here is actively bad and
   * being fixed later.
   *
   * @param array $params
   * @param array $contributionParams
   *   Parameters to be passed to contribution create action.
   *   This differs from params in that we are currently adding params to it and 1) ensuring they are being
   *   passed consistently & 2) documenting them here.
   *   - contact_id
   *   - line_item
   *   - is_test
   *   - campaign_id
   *   - source
   *   - payment_type_id
   *
   * @return \CRM_Contribute_DAO_Contribution
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  protected function processContribution(
    $params,
    $contributionParams
  ) {
    $form = $this;
    $transaction = new CRM_Core_Transaction();
    $contactID = $contributionParams['contact_id'];

    // add these values for the recurringContrib function ,CRM-10188
    $params['financial_type_id'] = $this->getFinancialTypeID();
    $params['is_recur'] = TRUE;
    $params['payment_instrument_id'] = $contributionParams['payment_instrument_id'] ?? NULL;
    $recurringContributionID = $this->legacyProcessRecurringContribution($params, $contactID);

    if ($recurringContributionID) {
      $contributionParams['contribution_recur_id'] = $recurringContributionID;
    }

    $contribution = CRM_Contribute_BAO_Contribution::add($contributionParams);

    // lets store it in the form variable so postProcess hook can get to this and use it
    $form->_contributionID = $contribution->id;

    $transaction->commit();
    return $contribution;
  }

  /**
   * Create the recurring contribution record.
   *
   * This function was copied from another form & needs cleanup.
   *
   * @param array $params
   * @param int $contactID
   *
   * @return int
   */
  protected function legacyProcessRecurringContribution(array $params, $contactID): int {

    $recurParams = ['contact_id' => $contactID];
    $recurParams['amount'] = $params['amount'] ?? NULL;
    $recurParams['auto_renew'] = $params['auto_renew'] ?? NULL;
    $recurParams['frequency_unit'] = $params['frequency_unit'] ?? NULL;
    $recurParams['frequency_interval'] = $params['frequency_interval'] ?? NULL;
    $recurParams['installments'] = $params['installments'] ?? NULL;
    $recurParams['financial_type_id'] = $this->getFinancialTypeID();
    $recurParams['currency'] = $params['currency'] ?? NULL;
    $recurParams['payment_instrument_id'] = $params['payment_instrument_id'];

    $recurParams['is_test'] = $this->isTest();

    $recurParams['start_date'] = $recurParams['create_date'] = $recurParams['modified_date'] = CRM_Utils_Time::date('YmdHis');
    if (!empty($params['receive_date'])) {
      $recurParams['start_date'] = date('YmdHis', CRM_Utils_Time::strtotime($params['receive_date']));
    }
    $recurParams['invoice_id'] = $this->getInvoiceID();
    $recurParams['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
    $recurParams['payment_processor_id'] = $params['payment_processor_id'] ?? NULL;
    $recurParams['is_email_receipt'] = (bool) $this->getSubmittedValue('send_receipt');
    // we need to add a unique trxn_id to avoid a unique key error
    // in paypal IPN we reset this when paypal sends us the real trxn id, CRM-2991
    $recurParams['trxn_id'] = $params['trxn_id'] ?? $this->getInvoiceID();

    $campaignId = $params['campaign_id'] ?? $this->_values['campaign_id'] ?? NULL;
    $recurParams['campaign_id'] = $campaignId;
    return CRM_Contribute_BAO_ContributionRecur::add($recurParams)->id;
  }

  /**
   * Is the form being submitted in test mode.
   *
   * @return bool
   */
  protected function isTest(): int {
    return ($this->_mode === 'test') ? TRUE : FALSE;
  }

  /**
   * Get the financial type id relevant to the contribution.
   *
   * Financial type id is optional when price sets are in use.
   * Otherwise they are required for the form to submit.
   *
   * @return int
   */
  protected function getFinancialTypeID(): int {
    return (int) $this->getSubmittedValue('financial_type_id') ?: $this->order->getFinancialTypeID();
  }

}

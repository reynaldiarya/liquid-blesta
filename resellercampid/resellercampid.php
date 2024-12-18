<?php
/**
 * Resellercampid Module
 *
 * @package blesta
 * @subpackage blesta.components.modules.resellercampid
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Resellercampid extends RegistrarModule {

    /**
     * Initializes the module
     */
    public function __construct ()
    {
        // Load configuration required by this module
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Load components required by this module
        Loader::loadComponents($this, array("Input"));

        // Load the language required by this module
        Language::loadLang("resellercampid", null, dirname(__FILE__) . DS . "language" . DS);

        Configure::load("resellercampid", dirname(__FILE__) . DS . "config" . DS);
    }

    /**
     * Performs migration of data from $current_version (the current installed version)
     * to the given file set version. Sets Input errors on failure, preventing
     * the module from being upgraded.
     *
     * @param string $current_version The current installed version of this module
     */
    public function upgrade($current_version)
    {
        // Upgrade to 2.1.0
        if (version_compare($current_version, '2.1.0', '<')) {
            Cache::clearCache(
                'tlds_prices',
                Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'resellercampid' . DS
            );
        }
    }

    /**
     * Attempts to validate service info. This is the top-level error checking method. Sets Input errors on failure.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars An array of user supplied info to satisfy the request
     * @return boolean True if the service validates, false otherwise. Sets Input errors when false.
     */
    public function validateService ($package, array $vars = null)
    {
        return true;
    }

    /**
     * Adds the service to the remote server. Sets Input errors on failure,
     * preventing the service from being added.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars An array of user supplied info to satisfy the request
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being added (if the current service is an addon service and parent service has already been provisioned)
     * @param string $status The status of the service being added. These include:
     * 	- active
     * 	- canceled
     * 	- pending
     * 	- suspended
     * @return array A numerically indexed array of meta fields to be stored for this service containing:
     * 	- key The key for this meta field
     * 	- value The value for this key
     * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function addService ($package, array $vars = null, $parent_package = null, $parent_service = null, $status = "pending")
    {

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");

        if (!isset($this->States))
            Loader::loadModels($this, array("States"));
        #
        # TODO: Handle validation checks
        #

        $tld = null;
        $input_fields = array();

        if (isset($vars['domain-name'])) {
            $vars['domain_name'] = $vars['domain-name'];
            $tld = $this->getTld($vars['domain-name'], true);
        }

        if ($package->meta->type == "domain") {
            $contact_fields = Configure::get("Resellercampid.contact_fields");
            $customer_fields = Configure::get("Resellercampid.customer_fields");
            $domain_field_basics = array(
                'years' => true,
                'domain_name' => true,
                'ns' => true,
                'customer_id' => true,
                'registrant_contact_id' => true,
                'admin_contact_id' => true,
                'tech_contact_id' => true,
                'billing_contact_id' => true,
                'invoice_option' => true,
                'purchase_privacy_protection' => true
            );
            $transfer_fields = array_merge(Configure::get("Resellercampid.transfer_fields"), $domain_field_basics);
            $domain_fields = array_merge(Configure::get("Resellercampid.domain_fields"), $domain_field_basics);
            $domain_contact_fields = (array) Configure::get("Resellercampid.contact_fields" . $tld);

            $input_fields = array_merge($contact_fields, $customer_fields, $transfer_fields, $domain_fields, $domain_field_basics, $domain_contact_fields);
        }

        if (isset($vars['use_module']) && $vars['use_module'] == "true") {
            if ($package->meta->type == "domain") {
                $api->loadCommand("resellercampid_domains");
                $domains = new ResellercampidDomains($api);

                $contact_type = $this->getContactType($tld);
                $order_id = null;
                $vars['years'] = 1;

                foreach ($package->pricing as $pricing) {
                    if ($pricing->id == $vars['pricing_id']) {
                        $vars['years'] = $pricing->term;
                        break;
                    }
                }

                // Set all whois info from client ($vars['client_id'])
                if (!isset($this->Clients))
                    Loader::loadModels($this, array("Clients"));

                if (!isset($this->Contacts))
                    Loader::loadModels($this, array("Contacts"));

                $contact_id = null;
                $client = $this->Clients->get($vars['client_id']);
                $contact_numbers = $this->Contacts->getNumbers($client->contact_id);
                $customer_id = $this->getCustomerId($package->module_row, $client->email);
                $client->numbers = $contact_numbers;

                foreach (array_merge($contact_fields, $customer_fields) as $key => $field) {
                    if ($key == "name")
                        $vars[$key] = $client->first_name . " " . $client->last_name;
                    elseif ($key == "company")
                        $vars[$key] = $client->company != "" ? $client->company : "Not Applicable";
                    elseif ($key == "email")
                        $vars[$key] = $client->email;
                    elseif ($key == "address_line_1")
                        $vars[$key] = $client->address1;
                    elseif ($key == "address_line_2")
                        $vars[$key] = $client->address2;
                    elseif ($key == "city")
                        $vars[$key] = $client->city;
                    elseif ($key == "state")
                        $vars[$key] = $client->state;
                    elseif ($key == "zipcode")
                        $vars[$key] = $client->zip;
                    elseif ($key == "country_code")
                        $vars[$key] = $client->country;
                    elseif ($key == "tel_cc_no") {
                        if (isset($client->numbers) AND is_array($client->numbers)) {
                            foreach ($client->numbers as $v_telp) {
                                $v_telp = (array) $v_telp;
                                $part = explode(".", $this->formatPhone($v_telp["number"], $client->country));
                                if (empty($part[1])) {
                                    $tel_cc = "";
                                    $tel = $part[0];
                                    $tel_cc = "62";
                                } else {
                                    $tel_cc = $part[0];
                                    $tel = $part[1];
                                }

                                $vars[$key] = ltrim($tel_cc, "+");
                                $vars['tel_no'] = ltrim($tel, "0");
                            }
                        }
                    } elseif ($key == "username")
                        $vars[$key] = $client->email;
                    elseif ($key == "password")
                        $vars[$key] = substr(md5(mt_rand()), 0, 15);
                    elseif ($key == "lang_pref")
                        $vars[$key] = substr($client->settings['language'], 0, 2);
                }

                if (!empty($vars["state"])) {
                    $real_state = (array) $this->States->get($vars["country_code"], $vars["state"]);
                    if (!empty($real_state["name"])) {
                        $vars["state"] = $real_state["name"];
                    }
                }

                // Set locality for .ASIA
                if ($tld == ".asia") {
                    $vars['eligibility_criteria'] = "asia";
                    $extra['asia_country'] = $client->country;
                    $extra['asia_entity_type'] = $vars["attr_legalentitytype"];
                    $extra['asia_identification_type'] = $vars["attr_identform"];
                    $extra['asia_identification_number'] = $vars["attr_identnumber"];
                    $vars['extra'] = http_build_query($extra);
                } elseif ($tld == ".ru") {
                    $vars['attr_org-r'] = $vars['company'];
                    $vars['attr_address-r'] = $vars['address-line-1'];
                    $vars['attr_person-r'] = $vars['name'];
                } elseif ($tld == ".us") {
                    $vars['eligibility_criteria'] = "us";
                    $extra['us_purpose'] = $vars["attr_purpose"];
                    $extra['us_category'] = $vars["attr_category"];
                    $vars['extra'] = http_build_query($extra);
                } elseif ($tld == ".co") {
                    $vars['eligibility_criteria'] = "co";
                }

                // Create customer if necessary
                if (!$customer_id)
                    $customer_id = $this->createCustomer($package->module_row, array_intersect_key($vars, array_merge($contact_fields, $customer_fields)));

                $vars['type'] = $contact_type;

                $vars['customer_id'] = $customer_id;
                $contact_id = $this->createContact($package->module_row, array_intersect_key($vars, array_merge($contact_fields, $domain_contact_fields)));
                $vars['registrant_contact_id'] = $contact_id;
                $contact_id = $this->createContact($package->module_row, array_intersect_key($vars, array_merge($contact_fields, $domain_contact_fields)));
                $vars['admin_contact_id'] = $this->formatContact($contact_id, $tld, "admin");
                $contact_id = $this->createContact($package->module_row, array_intersect_key($vars, array_merge($contact_fields, $domain_contact_fields)));
                $vars['tech_contact_id'] = $this->formatContact($contact_id, $tld, "tech");
                $contact_id = $this->createContact($package->module_row, array_intersect_key($vars, array_merge($contact_fields, $domain_contact_fields)));
                $vars['billing_contact_id'] = $this->formatContact($contact_id, $tld, "billing");
                $vars['invoice_option'] = "no_invoice";
                $vars['purchase_privacy_protection'] = "false";

                // Handle special contact assignment case for .ASIA
                if ($tld == ".asia") {
                    $domain_fields = array_merge($domain_fields, array("extra" => true));
                    $vars['extra'] = "asia_contact_id=" . $contact_id;
                    // Handle special assignment case for .AU
                } elseif ($tld == ".au") {
                    $vars['attr_eligibilityName'] = $client->company;
                    $vars['attr_registrantName'] = $client->first_name . " " . $client->last_name;
                } elseif ($tld == ".us") {
                    $domain_fields = array_merge($domain_fields, array("extra" => true));
                    $vars['extra'] = "us_contact_id=" . $contact_id;
                }

                $vars = array_merge($vars, $this->createMap($vars));

                // Handle transfer
                if (isset($vars['transfer']) || isset($vars['auth-code'])) {
                    $response = $domains->transfer(array_intersect_key($vars, $transfer_fields));
                }
                // Handle registration
                else {
                    // Set nameservers
                    $vars['ns_'] = array();
                    for ($i = 1; $i <= 5; $i++) {
                        if (isset($vars["ns" . $i]) && $vars["ns" . $i] != "")
                            $vars['ns_'][] = $vars["ns" . $i];
                    }

                    $vars['ns'] = implode(",", $vars["ns_"]);

                    $response = $domains->register(array_intersect_key($vars, $domain_fields));
                }

                $var_response = $response->response();
                if (!empty($var_response["domain_id"])) {
                    $order_id = $var_response["domain_id"];
                }

                $this->processResponse($api, $response);

                if ($this->Input->errors())
                    return;

                return array(
                    array('key' => "domain-name", 'value' => $vars['domain-name'], 'encrypted' => 0),
                    array('key' => "order-id", 'value' => $order_id, 'encrypted' => 0)
                );
            }
            else {
                #
                # TODO: Create SSL cert
                #
            }
        } elseif ($status != "pending" AND $status != "in_review") {
            if ($package->meta->type == "domain") {
                $api->loadCommand("resellercampid_domains");
                $domains = new ResellercampidDomains($api);

                $response = $domains->orderid($vars);
                $this->processResponse($api, $response);

                if ($this->Input->errors())
                    return;

                $order_id = null;
                if ($response->response()) {
                    $domain_data = $response->response();
                    $order_id = $domain_data["domain_id"];
                }

                return array(
                    array('key' => "domain-name", 'value' => $vars['domain-name'], 'encrypted' => 0),
                    array('key' => "order-id", 'value' => $order_id, 'encrypted' => 0)
                );
            }
        }

        $meta = array();
        $fields = array_intersect_key($vars, array_merge(array('ns1' => true, 'ns2' => true, 'ns3' => true, 'ns4' => true, 'ns5' => true), $input_fields));

        foreach ($fields as $key => $value) {
            $meta[] = array(
                'key' => $key,
                'value' => $value,
                'encrypted' => 0
            );
        }

        return $meta;
    }

    /**
     * Edits the service on the remote server. Sets Input errors on failure,
     * preventing the service from being edited.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $vars An array of user supplied info to satisfy the request
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being edited (if the current service is an addon service)
     * @return array A numerically indexed array of meta fields to be stored for this service containing:
     * 	- key The key for this meta field
     * 	- value The value for this key
     * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function editService ($package, $service, array $vars = array(), $parent_package = null, $parent_service = null)
    {

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $service_fields = $this->serviceFieldsToObject($service->fields);

        // Only update the service if 'use_module' is true
        if ($vars['use_module'] == "true") {
            // Nothing TO DO
        }

        return array(
            array('key' => "domain-name", 'value' => $vars['domain-name'], 'encrypted' => 0),
            array('key' => "order-id", 'value' => $vars['order-id'], 'encrypted' => 0)
        );
    }

    /**
     * Cancels the service on the remote server. Sets Input errors on failure,
     * preventing the service from being canceled.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being canceled (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
     * 	- key The key for this meta field
     * 	- value The value for this key
     * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function cancelService ($package, $service, $parent_package = null, $parent_service = null)
    {
        return null; // Nothing to do
    }

    /**
     * Suspends the service on the remote server. Sets Input errors on failure,
     * preventing the service from being suspended.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being suspended (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
     * 	- key The key for this meta field
     * 	- value The value for this key
     * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function suspendService ($package, $service, $parent_package = null, $parent_service = null)
    {
        return null; // Nothing to do
    }

    /**
     * Unsuspends the service on the remote server. Sets Input errors on failure,
     * preventing the service from being unsuspended.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being unsuspended (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
     * 	- key The key for this meta field
     * 	- value The value for this key
     * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function unsuspendService ($package, $service, $parent_package = null, $parent_service = null)
    {
        return null; // Nothing to do
    }

    /**
     * Allows the module to perform an action when the service is ready to renew.
     * Sets Input errors on failure, preventing the service from renewing.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being renewed (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
     * 	- key The key for this meta field
     * 	- value The value for this key
     * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function renewService ($package, $service, $parent_package = null, $parent_service = null)
    {

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");

        // Renew domain
        if ($package->meta->type == "domain") {
            $fields = $this->serviceFieldsToObject($service->fields);

            $response = $domains->details(array('order-id' => $fields->{'order-id'}, 'fields' => "domain_details"));
            $this->processResponse($api, $response);
            $order = (array) $response->response();

            $vars = array(
                'years' => 1,
                'domain_id' => $fields->{'order-id'},
                'current_date' => $order["end_date"],
                'invoice_option' => "no_invoice"
            );

            foreach ($package->pricing as $pricing) {
                if ($pricing->id == $service->pricing_id) {
                    $vars['years'] = $pricing->term;
                    break;
                }
            }

            // Only process renewal if adding years today will add time to the expiry date
            if (strtotime("+" . $vars['years'] . " years") > $order["end_date"]) {
                $api->loadCommand("resellercampid_domains");
                $domains = new ResellercampidDomains($api);
                $response = $domains->renew($vars);
                $this->processResponse($api, $response);
            }
        } else {
            #
            # TODO: SSL Cert: Set cancelation date of service?
            #
        }

        return null;
    }

    /**
     * Updates the package for the service on the remote server. Sets Input
     * errors on failure, preventing the service's package from being changed.
     *
     * @param stdClass $package_from A stdClass object representing the current package
     * @param stdClass $package_to A stdClass object representing the new package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being changed (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be stored for this service containing:
     * 	- key The key for this meta field
     * 	- value The value for this key
     * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function changeServicePackage ($package_from, $package_to, $service, $parent_package = null, $parent_service = null)
    {
        return null; // Nothing to do
    }

    /**
     * Validates input data when attempting to add a package, returns the meta
     * data to save when adding a package. Performs any action required to add
     * the package on the remote server. Sets Input errors on failure,
     * preventing the package from being added.
     *
     * @param array An array of key/value pairs used to add the package
     * @return array A numerically indexed array of meta fields to be stored for this package containing:
     * 	- key The key for this meta field
     * 	- value The value for this key
     * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function addPackage (array $vars = null)
    {

        $meta = array();
        if (isset($vars['meta']) && is_array($vars['meta'])) {
            // Return all package meta fields
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = array(
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                );
            }
        }

        return $meta;
    }

    /**
     * Validates input data when attempting to edit a package, returns the meta
     * data to save when editing a package. Performs any action required to edit
     * the package on the remote server. Sets Input errors on failure,
     * preventing the package from being edited.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array An array of key/value pairs used to edit the package
     * @return array A numerically indexed array of meta fields to be stored for this package containing:
     * 	- key The key for this meta field
     * 	- value The value for this key
     * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function editPackage ($package, array $vars = null)
    {

        $meta = array();
        if (isset($vars['meta']) && is_array($vars['meta'])) {
            // Return all package meta fields
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = array(
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                );
            }
        }

        return $meta;
    }

    /**
     * Returns the rendered view of the manage module page
     *
     * @param mixed $module A stdClass object representing the module and its rows
     * @param array $vars An array of post data submitted to or on the manage module page (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the manager module page
     */
    public function manageModule ($module, array &$vars)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View("manage", "default");
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView("components" . DS . "modules" . DS . "resellercampid" . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html", "Widget"));

        $this->view->set("module", $module);

        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the add module row page
     *
     * @param array $vars An array of post data submitted to or on the add module row page (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the add module row page
     */
    public function manageAddRow (array &$vars)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View("add_row", "default");
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView("components" . DS . "modules" . DS . "resellercampid" . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html", "Widget"));

        // Set unspecified checkboxes
        if (!empty($vars)) {
            if (empty($vars['sandbox']))
                $vars['sandbox'] = "false";
        }

        $this->view->set("vars", (object) $vars);
        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the edit module row page
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     * @param array $vars An array of post data submitted to or on the edit module row page (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the edit module row page
     */
    public function manageEditRow ($module_row, array &$vars)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View("edit_row", "default");
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView("components" . DS . "modules" . DS . "resellercampid" . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html", "Widget"));

        if (empty($vars))
            $vars = $module_row->meta;
        else {
            // Set unspecified checkboxes
            if (empty($vars['sandbox']))
                $vars['sandbox'] = "false";
        }

        $this->view->set("vars", (object) $vars);
        return $this->view->fetch();
    }

    /**
     * Adds the module row on the remote server. Sets Input errors on failure,
     * preventing the row from being added.
     *
     * @param array $vars An array of module info to add
     * @return array A numerically indexed array of meta fields for the module row containing:
     * 	- key The key for this meta field
     * 	- value The value for this key
     * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     */
    public function addModuleRow (array &$vars)
    {
        $meta_fields = array("registrar", "reseller_id", "key", "sandbox");
        $encrypted_fields = array("key");

        // Set unspecified checkboxes
        if (empty($vars['sandbox']))
            $vars['sandbox'] = "false";

        $this->Input->setRules($this->getRowRules($vars));

        // Validate module row
        if ($this->Input->validates($vars)) {

            // Build the meta data for this row
            $meta = array();
            foreach ($vars as $key => $value) {
                if (in_array($key, $meta_fields)) {
                    $meta[] = array(
                        'key' => $key,
                        'value' => $value,
                        'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0
                    );
                }
            }

            return $meta;
        }
    }

    /**
     * Edits the module row on the remote server. Sets Input errors on failure,
     * preventing the row from being updated.
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     * @param array $vars An array of module info to update
     * @return array A numerically indexed array of meta fields for the module row containing:
     * 	- key The key for this meta field
     * 	- value The value for this key
     * 	- encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     */
    public function editModuleRow ($module_row, array &$vars)
    {
        // Same as adding
        return $this->addModuleRow($vars);
    }

    /**
     * Deletes the module row on the remote server. Sets Input errors on failure,
     * preventing the row from being deleted.
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     */
    public function deleteModuleRow ($module_row)
    {
        // Nothing to do
    }

    /**
     * Returns all fields used when adding/editing a package, including any
     * javascript to execute when the page is rendered with these fields.
     *
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional HTML markup to include
     */
    public function getPackageFields ($vars = null)
    {
        Loader::loadHelpers($this, array("Html"));

        $fields = new ModuleFields();

        $types = array(
            'domain' => Language::_("Resellercampid.package_fields.type_domain", true),
                //'ssl' => Language::_("Resellercampid.package_fields.type_ssl", true)
        );

        // Set type of package
        $type = $fields->label(Language::_("Resellercampid.package_fields.type", true), "resellercampid_type");
        $type->attach(
            $fields->fieldSelect(
                "meta[type]",
                $types,
                (isset($vars->meta['type']) ? $vars->meta['type'] : null),
                array('id' => "resellercampid_type")
            )
        );
        $fields->setField($type);

        // Set all TLD checkboxes
        $tld_options = $fields->label(Language::_("Resellercampid.package_fields.tld_options", true));

        $tlds = Configure::get("Resellercampid.tlds");
        sort($tlds);
        foreach ($tlds as $tld) {
            $tld_label = $fields->label($tld, "tld_" . $tld);
            $tld_options->attach(
                $fields->fieldCheckbox(
                    "meta[tlds][]",
                    $tld,
                    (isset($vars->meta['tlds']) && in_array($tld, $vars->meta['tlds'])),
                    array('id' => "tld_" . $tld),
                    $tld_label
                )
            );
        }
        $fields->setField($tld_options);

        // Set nameservers
        for ($i = 1; $i <= 5; $i++) {
            $type = $fields->label(Language::_("Resellercampid.package_fields.ns" . $i, true), "resellercampid_ns" . $i);
            $type->attach($fields->fieldText("meta[ns][]", $this->Html->ifSet($vars->meta['ns'][$i - 1]), array('id' => "resellercampid_ns" . $i)));
            $fields->setField($type);
        }

        $fields->setHtml("
            <script type=\"text/javascript\">
                $(document).ready(function() {
                    toggleTldOptions($('#resellercampid_type').val());

                    // Re-fetch module options to toggle fields
                    $('#resellercampid_type').change(function() {
                        toggleTldOptions($(this).val());
                    });

                    function toggleTldOptions(type) {
                        if (type == 'ssl')
                                $('.resellercampid_tlds').hide();
                        else
                                $('.resellercampid_tlds').show();
                    }
                });
            </script>
        ");

        return $fields;
    }

    /**
     * Returns all fields to display to an admin attempting to add a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional HTML markup to include
     */
    public function getAdminAddFields ($package, $vars = null)
    {
        // Handle universal domain name
        if (isset($vars->domain))
            $vars->{'domain-name'} = $vars->domain;

        if ($package->meta->type == "domain") {
            // Set default name servers
            if (!isset($vars->ns1) && isset($package->meta->ns)) {
                $i = 1;
                foreach ($package->meta->ns as $ns) {
                    $vars->{"ns" . $i++} = $ns;
                }
            }

            // Handle transfer request
            if (isset($vars->transfer) || isset($vars->{'auth-code'})) {
                return $this->arrayToModuleFields(Configure::get("Resellercampid.transfer_fields"), null, $vars);
            }
            // Handle domain registration
            else {

                $module_fields = $this->arrayToModuleFields(array_merge(Configure::get("Resellercampid.domain_fields"), Configure::get("Resellercampid.nameserver_fields")), null, $vars);

                if (isset($vars->{'domain-name'})) {
                    $tld = $this->getTld($vars->{'domain-name'});

                    if ($tld) {
                        $extension_fields = array_merge((array) Configure::get("Resellercampid.domain_fields" . $tld), (array) Configure::get("Resellercampid.contact_fields" . $tld));
                        if ($extension_fields)
                            $module_fields = $this->arrayToModuleFields($extension_fields, $module_fields, $vars);
                    }
                }

                return $module_fields;
            }
        }
        else {
            return new ModuleFields();
        }
    }

    /**
     * Returns all fields to display to a client attempting to add a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional HTML markup to include
     */
    public function getClientAddFields ($package, $vars = null)
    {

        // Handle universal domain name
        if (isset($vars->domain))
            $vars->{'domain-name'} = $vars->domain;

        if ($package->meta->type == "domain") {

            // Set default name servers
            if (!isset($vars->ns1) && isset($package->meta->ns)) {
                $i = 1;
                foreach ($package->meta->ns as $ns) {
                    $vars->{"ns" . $i++} = $ns;
                }
            }

            $tld = (property_exists($vars, "domain-name") ? $this->getTld($vars->{'domain-name'}, true) : null);

            // Handle transfer request
            if (isset($vars->transfer) || isset($vars->{'auth-code'})) {
                $fields = Configure::get("Resellercampid.transfer_fields");

                // We should already have the domain name don't make editable
                $fields['domain-name']['type'] = "hidden";
                $fields['domain-name']['label'] = null;

                $module_fields = $this->arrayToModuleFields($fields, null, $vars);

                $extension_fields = Configure::get("Resellercampid.contact_fields" . $tld);
                if ($extension_fields)
                    $module_fields = $this->arrayToModuleFields($extension_fields, $module_fields, $vars);

                return $module_fields;
            }
            // Handle domain registration
            else {
                $fields = array_merge(Configure::get("Resellercampid.nameserver_fields"), Configure::get("Resellercampid.domain_fields"));

                // We should already have the domain name don't make editable
                $fields['domain-name']['type'] = "hidden";
                $fields['domain-name']['label'] = null;

                $module_fields = $this->arrayToModuleFields($fields, null, $vars);

                if (isset($vars->{'domain-name'})) {
                    $extension_fields = array_merge((array) Configure::get("Resellercampid.domain_fields" . $tld), (array) Configure::get("Resellercampid.contact_fields" . $tld));
                    if ($extension_fields)
                        $module_fields = $this->arrayToModuleFields($extension_fields, $module_fields, $vars);
                }

                return $module_fields;
            }
        }
        else {
            return new ModuleFields();
        }
    }

    /**
     * Returns all fields to display to an admin attempting to edit a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional HTML markup to include
     */
    public function getAdminEditFields ($package, $vars = null)
    {
        if ($package->meta->type == "domain") {

            Loader::loadHelpers($this, array("Html"));

            $fields = new ModuleFields();

            // Get The orderid if missing in the database (usefull for imported services)

            if (property_exists($fields, "order-id"))
                $order_id = $vars->{'order-id'};
            else {
                $parts = explode('/', $_SERVER['REQUEST_URI']);
                $order_id = $this->getorderid($package->module_row, $vars->{'domain-name'});
                $this->UpdateOrderID($package, array('service-id' => $parts[sizeof($parts) - 2], 'domain-name' => $vars->{'domain-name'}));
            }

            // Create domain label
            $domain = $fields->label(Language::_("Resellercampid.domain.domain-name", true), "domain-name");
            // Create domain field and attach to domain label
            $domain->attach($fields->fieldText("domain-name", $this->Html->ifSet($vars->{'domain-name'}), array('id' => "domain-name")));
            // Set the label as a field
            $fields->setField($domain);

            // Create Order-id label
            $orderid = $fields->label(Language::_("Resellercampid.domain.order-id", true), "order-id");
            // Create orderid field and attach to orderid label
            $orderid->attach($fields->fieldText("order-id", $this->Html->ifSet($order_id), array('id' => "order-id")));
            // Set the label as a field
            $fields->setField($orderid);

            return $fields;
        } else {
            return new ModuleFields();
        }
    }

    /**
     * Fetches the HTML content to display when viewing the service info in the
     * admin interface.
     *
     * @param stdClass $service A stdClass object representing the service
     * @param stdClass $package A stdClass object representing the service's package
     * @return string HTML content containing information to display when viewing the service info
     */
    public function getAdminServiceInfo ($service, $package)
    {
        return "";
    }

    /**
     * Fetches the HTML content to display when viewing the service info in the
     * client interface.
     *
     * @param stdClass $service A stdClass object representing the service
     * @param stdClass $package A stdClass object representing the service's package
     * @return string HTML content containing information to display when viewing the service info
     */
    public function getClientServiceInfo ($service, $package)
    {
        return "";
    }

    /**
     * Returns all tabs to display to an admin when managing a service whose
     * package uses this module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @return array An array of tabs in the format of method => title. Example: array('methodName' => "Title", 'methodName2' => "Title2")
     */
    public function getAdminTabs ($package)
    {
        if ($package->meta->type == "domain") {
            return array(
                'tabWhois' => Language::_("Resellercampid.tab_whois.title", true),
                'tabNameservers' => Language::_("Resellercampid.tab_nameservers.title", true),
                'tabChildname' => Language::_("Resellercampid.tab_childname.title", true),
                'tabManagedns' => Language::_("Resellercampid.tab_managedns.title", true),
                'tabDomainForwarding' => Language::_("Resellercampid.tab_domainforwarding.title", true),
                'tabSettings' => Language::_("Resellercampid.tab_settings.title", true),
            );
        } else {
            #
            # TODO: Activate & uploads CSR, set field data, etc.
            #
        }
    }

    /**
     * Returns all tabs to display to a client when managing a service whose
     * package uses this module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @return array An array of tabs in the format of method => title. Example: array('methodName' => "Title", 'methodName2' => "Title2")
     */
    public function getClientTabs ($package)
    {
        if ($package->meta->type == "domain") {
            return array(
                'tabClientWhois' => Language::_("Resellercampid.tab_whois.title", true),
                'tabClientNameservers' => Language::_("Resellercampid.tab_nameservers.title", true),
                'tabClientChildname' => Language::_("Resellercampid.tab_childname.title", true),
                'tabClientManagedns' => Language::_("Resellercampid.tab_managedns.title", true),
                'tabClientDomainForwarding' => Language::_("Resellercampid.tab_domainforwarding.title", true),
                'tabClientSettings' => Language::_("Resellercampid.tab_settings.title", true)
            );
        } else {
            #
            # TODO: Activate & uploads CSR, set field data, etc.
            #
        }
    }

    /**
     * Admin Whois tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabWhois ($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageWhois("tab_whois", $package, $service, $get, $post, $files);
    }

    /**
     * Client Whois tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientWhois ($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageWhois("tab_client_whois", $package, $service, $get, $post, $files);
    }

    /**
     * Admin Nameservers tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabNameservers ($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageNameservers("tab_nameservers", $package, $service, $get, $post, $files);
    }

    /**
     * Admin Nameservers tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientNameservers ($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageNameservers("tab_client_nameservers", $package, $service, $get, $post, $files);
    }

    /**
     * Admin Settings tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabSettings ($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageSettings("tab_settings", $package, $service, $get, $post, $files);
    }

    /**
     * Client Settings tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientSettings ($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageSettings("tab_client_settings", $package, $service, $get, $post, $files);
    }

    /**
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabManagedns ($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDNS("tab_managedns", $package, $service, $get, $post, $files);
    }

    /**
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabDomainForwarding ($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDomainForwarding("tab_domainforwarding", $package, $service, $get, $post, $files);
    }

    public function tabClientDomainForwarding ($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDomainForwarding("tab_client_domainforwarding", $package, $service, $get, $post, $files);
    }

    /**
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientManagedns ($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDNS("tab_client_managedns", $package, $service, $get, $post, $files);
    }

    /**
     * Admin Childname Server tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabChildname ($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageChildname("tab_childname", $package, $service, $get, $post, $files);
    }

    /**
     * Client Childname Server tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientChildname ($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageChildname("tab_client_childname", $package, $service, $get, $post, $files);
    }

    /**
     * Handle updating whois information
     *
     * @param string $view The view to use
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    private function manageWhois ($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $api->loadCommand("resellercampid_domains");
        $domains = new ResellercampidDomains($api);

        if (!isset($this->Countries))
            Loader::loadModels($this, array("Countries"));

        $vars = new stdClass();

        $contact_fields = Configure::get("Resellercampid.contact_fields");
        $fields = $this->serviceFieldsToObject($service->fields);
        $sections = array('registrantcontact', 'admincontact', 'techcontact', 'billingcontact');

        $show_content = true;

        if (!empty($post)) {

            $countries = $this->Countries->getList();
            foreach ($countries as $v_countries) {
                $country[$v_countries->name] = $v_countries->alpha2;
                $country[$v_countries->alpha2] = $v_countries->name;
            }

            $dom_detail = $domains->details(array('order-id' => $fields->{'order-id'}, 'fields' => "domain_details"));
            $data_dom_detail = $dom_detail->response();
            $customer_id = $data_dom_detail["customer_id"];

            $api->loadCommand("resellercampid_contacts");
            $contacts = new ResellercampidContacts($api);

            foreach ($sections as $section) {
                $contact = array();
                foreach ($post as $key => $value) {
                    if (strpos($key, $section . "_") !== false && $value != "")
                        $contact[str_replace($section . "_", "", $key)] = $value;
                }
                $contact["country_code"] = $country[$contact["country_code"]];
                $contact["contact_id"] = $contact["contact-id"];
                $response = $contacts->modify($contact, $customer_id);
                $this->processResponse($api, $response);
                if ($this->Input->errors())
                    break;
            }

            $vars = (object) $post;
        }
        elseif (property_exists($fields, "order-id")) {
            $response = $domains->details(array('order-id' => $fields->{'order-id'}, 'fields' => array("registrant_contact", "admin_contact", "tech_contact", "billing_contact")));
            if ($response->status() == "OK") {
                $data = $response->response();

                // Format fields
                foreach ($sections as $section) {
                    if ($section == "registrantcontact") {
                        $section_ = "registrant_contact";
                    }
                    if ($section == "admincontact") {
                        $section_ = "admin_contact";
                    }
                    if ($section == "techcontact") {
                        $section_ = "tech_contact";
                    }
                    if ($section == "billingcontact") {
                        $section_ = "billing_contact";
                    }

                    $vars_["order-id"] = $fields->{'order-id'};
                    $vars_["fields"] = $section_;
                    $res = $domains->details($vars_);
                    $data_res = $res->response();
                    foreach ($data_res as $key => $value) {
                        if ($key == "country_code") {
                            $value = $data_res["country"];
                        }
                        if ($key == "contact_id") {
                            $key = "contact-id";
                            $value = $data_res["contact_id"];
                        }
                        $vars->{$section . "_" . $key} = $value;
                    }
                }
            }
        } else {
            // No order-id; info is not available
            // $show_content = false;
            $this->UpdateOrderID($package, array('service-id' => $service->id, 'domain-name' => $fields->{'domain-name'}));
        }

        $contact_fields = array_merge(Configure::get("Resellercampid.contact_fields"), array('contact-id' => array('type' => "hidden")));
        unset($contact_fields['customer-id']);
        unset($contact_fields['type']);

        $all_fields = array();
        foreach ($contact_fields as $key => $value) {
            $all_fields['admincontact_' . $key] = $value;
            $all_fields['techcontact_' . $key] = $value;
            $all_fields['registrantcontact_' . $key] = $value;
            $all_fields['billingcontact_' . $key] = $value;
        }

        $module_fields = $this->arrayToModuleFields(Configure::get("Resellercampid.contact_fields"), null, $vars);

        $view = ($show_content ? $view : "tab_unavailable");
        $this->view = new View($view, "default");

        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html"));

        if (empty($vars->{'billingcontact_contact-id'})) {
            $this->UpdateOrderID($package, array('service-id' => $service->id, 'domain-name' => $fields->{'domain-name'}));
        }

        $this->view->set("vars", $vars);
        $this->view->set("fields", $this->arrayToModuleFields($all_fields, null, $vars)->getFields());
        $this->view->set("sections", $sections);
        $this->view->setDefaultView("components" . DS . "modules" . DS . "resellercampid" . DS);
        return $this->view->fetch();
    }

    /**
     * Handle updating nameserver information
     *
     * @param string $view The view to use
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    private function manageNameservers ($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $api->loadCommand("resellercampid_domains");
        $domains = new ResellercampidDomains($api);

        $fields = $this->serviceFieldsToObject($service->fields);
        $show_content = true;

        $tld = $this->getTld($fields->{'domain-name'});
        $sld = substr($fields->{'domain-name'}, 0, -strlen($tld));

        if (property_exists($fields, "order-id")) {
            if (!empty($post)) {
                $ns = array();
                foreach ($post['ns'] as $i => $nameserver) {
                    if ($nameserver != "")
                        $ns[] = $nameserver;
                }

                $ns_ = implode(",", $ns);
                $post['order-id'] = $fields->{'order-id'};
                $response = $domains->modifyNs(array('domain_id' => $fields->{'order-id'}, 'ns' => $ns_));
                $this->processResponse($api, $response);

                $vars = (object) $post;
            }
            else {
                $response = $domains->details(array('order-id' => $fields->{'order-id'}, 'fields' => "ns"))->response();

                $vars->ns = array();
                for ($i = 0; $i < 5; $i++) {
                    if (isset($response["ns" . ($i + 1)]))
                        $vars->ns[] = $response["ns" . ($i + 1)];
                }
            }
        }
        else {
            // No order-id; info is not available
            // $show_content = false;
            $this->UpdateOrderID($package, array('service-id' => $service->id, 'domain-name' => $fields->{'domain-name'}));
        }

        $view = ($show_content ? $view : "tab_unavailable");
        $this->view = new View($view, "default");

        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html"));

        if (empty($vars->ns[0])) {
            $this->UpdateOrderID($package, array('service-id' => $service->id, 'domain-name' => $fields->{'domain-name'}));
        }

        $this->view->set("vars", $vars);
        $this->view->setDefaultView("components" . DS . "modules" . DS . "resellercampid" . DS);
        return $this->view->fetch();
    }

    /**
     * Handle updating settings
     *
     * @param string $view The view to use
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    private function manageSettings ($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $api->loadCommand("resellercampid_domains");
        $domains = new ResellercampidDomains($api);

        $fields = $this->serviceFieldsToObject($service->fields);
        $show_content = true;


        if (property_exists($fields, "order-id")) {
            if (!empty($post)) {
                if (isset($post['registrar_lock'])) {
                    if ($post['registrar_lock'] == "true") {
                        $response = $domains->enableTheftProtection(array(
                            'order-id' => $fields->{'order-id'},
                        ));
                    } else {
                        $response = $domains->disableTheftProtection(array(
                            'order-id' => $fields->{'order-id'},
                        ));
                    }
                    $this->processResponse($api, $response);
                }

                $vars = (object) $post;
            } else {

                $response = $domains->details(array('domain_id' => $fields->{'order-id'}, 'fields' => "All"));
                $this->processResponse($api, $response);

                $data_domain = $response->response();

                if ($data_domain) {
                    $vars->registrar_lock = "false";
                    if ($data_domain["theft_protection"] == "true") {
                        $vars->registrar_lock = "true";
                    }

                    $getAuth = $domains->getAuthCode(array('order-id' => $data_domain['domain_id']));
                    $this->processResponse($api, $getAuth);

                    $auth_code = $getAuth->response();

                    $vars->epp_code = ($auth_code) ? $auth_code : 'null';
                }
            }
        } else {
            $this->UpdateOrderID($package, array('service-id' => $service->id, 'domain-name' => $fields->{'domain-name'}));
            //$show_content = false;
        }

        $view = ($show_content ? $view : "tab_unavailable");
        $this->view = new View($view, "default");

        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html"));

        $this->view->set("vars", $vars);
        $this->view->setDefaultView("components" . DS . "modules" . DS . "resellercampid" . DS);
        return $this->view->fetch();
    }

    private function manageDomainForwarding ($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = array();
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $api->loadCommand("resellercampid_dns_manage");
        $dns = new ResellercampidDnsManage($api);
        $fields = $this->serviceFieldsToObject($service->fields);
        $show_content = true;

        $domain_id = $fields->{'order-id'};

        if (!empty($post)) {
            $post_var["domain_id"] = $domain_id;
            $post_var["forward_to"] = $post["destination"];
            $post_var["url_masking"] = "false";
            if ($post["urlmask"] == "on") {
                $post_var["url_masking"] = "true";
            }
            $post_var["meta_tags"] = $post["headertag"];
            $post_var["no_frames_content"] = $post["noframe"];
            $post_var["path_forwarding"] = "false";
            if ($post["path"] == "on") {
                $post_var["path_forwarding"] = "true";
            }
            $post_var["subdomain_forwarding"] = "false";
            if ($post["subdomain"] == "on") {
                $post_var["subdomain_forwarding"] = "true";
            }
            $response = $dns->updateDomainForwarding($post_var);
            $this->processResponse($api, $response);
        }

        $ret_data["domain_id"] = $domain_id;
        $ret_data["domain_name"] = $fields->{'domain-name'};

        $data_dns = $dns->retrieveDomainForwarding($ret_data)->response();
        $vars["forwarding"] = $data_dns;
        $vars["domain_name"] = $ret_data["domain_name"];

        $view = ($show_content ? $view : "tab_unavailable");
        $this->view = new View($view, "default");

        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html"));

        $this->view->set("vars", $vars);

        $this->view->setDefaultView("components" . DS . "modules" . DS . "resellercampid" . DS);
        return $this->view->fetch();
    }

    private function manageDNS ($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $api->loadCommand("resellercampid_dns_manage");
        $dns = new ResellercampidDnsManage($api);
        $fields = $this->serviceFieldsToObject($service->fields);
        $show_content = true;

        $domain_id = $fields->{'order-id'};

        if (!empty($post)) {
            $post_var["domain_id"] = $domain_id;
            if (!empty($post["old-hostname"])) {
                $post_var["old_hostname"] = $post["old-hostname"];
                $post_var["hostname"] = $post["old-hostname"];
            }
            if (!empty($post["old-value"])) {
                $post_var["old_value"] = $post["old-value"];
            }
            if (!empty($post["value"])) {
                $post_var["value"] = $post["value"];
            }

            if ($post["submit"] == "update") {
                if ($post["type"] == "A") {
                    $post_var["old-ip"] = $post_var["old_value"];
                    $post_var["ip"] = $post_var["value"];
                    $response = $dns->updateIpv4Record($post_var);
                    $this->processResponse($api, $response);
                }
                if ($post["type"] == "AAAA") {
                    $post_var["old-ip"] = $post_var["old-value"];
                    $post_var["ip"] = $post_var["value"];
                    $response = $dns->updateIpv6Record($post_var);
                    $this->processResponse($api, $response);
                }
                if ($post["type"] == "CNAME") {
                    $response = $dns->updateCnameRecord($post_var);
                    $this->processResponse($api, $response);
                }
                if ($post["type"] == "MX") {
                    $post_var["priority"] = $post["priority"];
                    $response = $dns->updateMxRecord($post_var);
                    $this->processResponse($api, $response);
                }
                if ($post["type"] == "TXT") {
                    $response = $dns->updateTxtRecord($post_var);
                    $this->processResponse($api, $response);
                }
            }

            if ($post["submit"] == "delete") {
                if ($post["type"] == "A") {
                    $post_var["old-ip"] = $post_var["old_value"];
                    $post_var["ip"] = $post_var["value"];
                    $response = $dns->deleteIpv4Record($post_var);
                    $this->processResponse($api, $response);
                }
                if ($post["type"] == "AAAA") {
                    $post_var["old-ip"] = $post_var["old-value"];
                    $post_var["ip"] = $post_var["value"];
                    $response = $dns->deleteIpv6Record($post_var);
                    $this->processResponse($api, $response);
                }
                if ($post["type"] == "CNAME") {
                    $response = $dns->deleteCnameRecord($post_var);
                    $this->processResponse($api, $response);
                }
                if ($post["type"] == "MX") {
                    $post_var["priority"] = $post["priority"];
                    $response = $dns->deleteMxRecord($post_var);
                    $this->processResponse($api, $response);
                }
                if ($post["type"] == "TXT") {
                    $response = $dns->deleteTxtRecord($post_var);
                    $this->processResponse($api, $response);
                }
            }

            if ($post["submit"] == "add") {
                if (!empty($post["hostname"])) {
                    $post_var["hostname"] = $post["hostname"];
                }
                if (!empty($post["priority"])) {
                    $post_var["priority"] = $post["priority"];
                }

                if ($post["type"] == "A") {
                    $response = $dns->addIpv4Record($post_var);
                    $this->processResponse($api, $response);
                }
                if ($post["type"] == "AAAA") {
                    $response = $dns->addIpv6Record($post_var);
                    $this->processResponse($api, $response);
                }
                if ($post["type"] == "CNAME") {
                    $response = $dns->addCnameRecord($post_var);
                    $this->processResponse($api, $response);
                }
                if ($post["type"] == "MX") {
                    $response = $dns->addMxRecord($post_var);
                    $this->processResponse($api, $response);
                }
                if ($post["type"] == "TXT") {
                    $response = $dns->addTxtRecord($post_var);
                    $this->processResponse($api, $response);
                }
            }
        }

        $ret_data["domain_id"] = $domain_id;
        $data_dns = $dns->retrieve($ret_data)->response();
        $vars["dns"] = $data_dns;

        $view = ($show_content ? $view : "tab_unavailable");
        $this->view = new View($view, "default");

        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html"));

        $this->view->set("vars", $vars);

        $this->view->setDefaultView("components" . DS . "modules" . DS . "resellercampid" . DS);
        return $this->view->fetch();
    }

    /**
     * Handle Manage Child Name server  information
     *
     * @param string $view The view to use
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    private function manageChildname ($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $api->loadCommand("resellercampid_domains");
        $domains = new ResellercampidDomains($api);

        $fields = $this->serviceFieldsToObject($service->fields);
        $show_content = true;

        if (property_exists($fields, "order-id")) {
            if (!empty($post)) {
                $postArray = array();
                switch ($post['submit']) {
                    case 'add':
                        $postArray['domain_id'] = $fields->{'order-id'};
                        $postArray['hostname'] = $post['cns'];
                        $postArray['ip_address'] = $post['ip'];
                        $response = $domains->addCns($postArray);
                        $this->processResponse($api, $response);
                        break;
                    case 'delete':
                        $postArray = array(
                            'domain_id' => $fields->{'order-id'},
                            'hostname' => $post['cns'],
                            'ip_address' => $post['ip']
                        );
                        $response = $domains->deleteCnsIp($postArray);
                        $this->processResponse($api, $response);
                        break;

                    case 'update':
                        if ($post['cns'] != $post['old-cns'] || $post['ip'] != $post['old-ip']) {
                            $postArray["order-id"] = $fields->{'order-id'};
                            $postArray["hostname"] = $post['cns'];
                            $postArray["old-ip"] = $post['old-ip'];
                            $postArray["ip_address"] = $post['ip'];
                            $postArray["old-cns"] = $post['old-cns'];
                            $response2 = $domains->modifyCnsIp($postArray);
                            $this->processResponse($api, $response2);
                        }
                        break;

                    default:

                        break;
                }
                // $ns = array();
                // foreach ($post['ns'] as $i => $nameserver) {
                // if ($nameserver != "")
                // $ns[] = $nameserver;
                // }
                // $post['order-id'] = $fields->{'order-id'};
                // $response = $domains->modifyNs(array('order-id' => $fields->{'order-id'}, 'ns' => $ns));
                // $this->processResponse($api, $response);
            } else {
                // $vars = $domains->details(array('order-id' => $fields->{'order-id'}, 'options' => "All"))->response();
            }
            $res_vars = $domains->details(array('domain_id' => $fields->{'order-id'}, 'fields' => "All"));
            $vars = $res_vars->response();
        } else {
            // No order-id; info is not available
            // $show_content = false;
            $this->UpdateOrderID($package, array('service-id' => $service->id, 'domain-name' => $fields->{'domain-name'}));
        }

        $view = ($show_content ? $view : "tab_unavailable");
        $this->view = new View($view, "default");

        // Load the helpers required for this view
        Loader::loadHelpers($this, array("Form", "Html"));

        $this->view->set("vars", $vars);
        $this->view->setDefaultView("components" . DS . "modules" . DS . "resellercampid" . DS);
        return $this->view->fetch();
    }

    /**
     * Creates a customer
     *
     * @param int $module_row_id The module row ID to add the customer under
     * @param array $vars An array of customer information
     * @return int The customer-id created, null otherwise
     * @see ResellercampidCustomers::signup()
     */
    private function createCustomer ($module_row_id, $vars)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $api->loadCommand("resellercampid_customers");
        $customers = new ResellercampidCustomers($api);

        $response = $customers->signup($vars);

        $this->processResponse($api, $response);

        if (!$this->Input->errors()) {
            $respon = $response->response();
            return $respon["customer_id"];
        }

        return null;
    }

    /**
     * Creates a contact
     *
     * @param int $module_row_id The module row ID to add the contact under
     * @param array $vars An array of contact information
     * @return int The contact-id created, null otherwise
     * @see ResellercampidContacts::add()
     */
    private function createContact ($module_row_id, $vars)
    {
        unset($vars['lang-pref'], $vars['username'], $vars['passwd']);

        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $api->loadCommand("resellercampid_contacts");
        $contacts = new ResellercampidContacts($api);

        $vars = array_merge($vars, $this->createMap($vars));

        $response = $contacts->add($vars);

        $this->processResponse($api, $response);

        if ($response->status() != "OK") {
            return null;
        }

        $contact = $response->response();

        return $contact["contact_id"];
    }

    /**
     * Fetches the resellercampid customer ID based on username
     *
     * @param int $module_row_id The module row ID to search on
     * @param string $username The customer username (should be an email address)
     * @return int The resellercampid customer-id if one exists, null otherwise
     */
    private function getCustomerId ($module_row_id, $username)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $api->loadCommand("resellercampid_customers");
        $customers = new ResellercampidCustomers($api);

        $vars = array('email' => $username, 'no-of-records' => 10, 'page-no' => 1);
        $response = $customers->search($vars);

        $response_data = $response->response();

        if (!empty($response_data[0]["customer_id"])) {
            foreach ($response_data as $v) {
                if ($v["email"] == $username) {
                    return $v["customer_id"];
                }
            }
        }

        return null;
    }

    /**
     * Fetches a resellercampid contact ID of a given resellercampid customer ID
     *
     * @param int $module_row_id The module row ID to search on
     * @param string $customer_id The resellercampid customer-id
     * @param string $type includes one of:
     * 	- Contact
     * 	- CoopContact
     * 	- UkContact
     * 	- EuContact
     * 	- Sponsor
     * 	- CnContact
     * 	- CoContact
     * 	- CaContact
     * 	- DeContact
     * 	- EsContact
     * @return int The resellercampid contact-id if one exists, null otherwise
     */
    private function getContactId ($module_row_id, $customer_id, $type = "Contact")
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $api->loadCommand("resellercampid_contacts");
        $contacts = new ResellercampidContacts($api);

        $vars = array('customer-id' => $customer_id, 'no-of-records' => 10, 'page-no' => 1, 'type' => $type);
        $response = $contacts->search($vars);

        $this->processResponse($api, $response);

        if (isset($response->response()->{'1'}->{'entity.entitiyid'}))
            return $response->response()->{'1'}->{'entity.entitiyid'};
        return null;
    }

    /**
     * Return the contact type required for the given TLD
     *
     * @param $tld The TLD to return the contact type for
     * @return string The contact type
     */
    private function getContactType ($tld)
    {
        $type = "Contact";
        // Detect contact type from TLD
        if (($tld_part = ltrim(strstr($tld, "."), ".")) &&
                in_array($tld_part, array("ca", "cn", "co", "coop", "de", "es", "eu", "nl", "ru", "uk"))) {
            $type = ucfirst($tld_part) . $type;
        }
        return $type;
    }

    /**
     * Create a so-called 'map' of attr-name and attr-value fields to cope with Resellercampid
     * ridiculous format requirements.
     *
     * @param $attr array An array of key/value pairs
     * @retrun array An array of key/value pairs where each $attr[$key] becomes "attr-nameN" and "attr-valueN" whose values are $key and $attr[$key], respectively
     */
    private function createMap ($attr)
    {
        $map = array();

        $i = 1;
        foreach ($attr as $key => $value) {
            if (substr($key, 0, 5) == "attr_") {
                $map['attr-name' . $i] = str_replace("attr_", "", $key);
                $map['attr-value' . $i] = $value;
                $i++;
            }
        }
        return $map;
    }

    /**
     * Performs a whois lookup on the given domain
     *
     * @param string $domain The domain to lookup
     * @return boolean True if available, false otherwise
     */
    public function checkAvailability ($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $api->loadCommand("resellercampid_domains");
        $domains = new ResellercampidDomains($api);
        $result = $domains->available(array('domain' => $domain));

        if ($result->status() != "OK")
            return false;

        $response = $result->response();

        if (!empty($response[0]["$domain"]["status"]) AND $response[0]["$domain"]["status"] == "available") {
            return true;
        }
        return false;
    }

    /**
     * Gets the domain expiration date
     *
     * @param stdClass $service The service belonging to the domain to lookup
     * @param string $format The format to return the expiration date in
     * @return string The domain expiration date in UTC time in the given format
     * @see Services::get()
     */
    public function getExpirationDate($service, $format = 'Y-m-d H:i:s')
    {
        Loader::loadHelpers($this, ['Date']);

        $domain = $this->getServiceDomain($service);
        $module_row_id = $service->module_row_id ?? null;

        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == 'true');
        $api->loadCommand('resellercampid_domains');
        $domains = new ResellercampidDomains($api);

        $result = $domains->detailsByName(['domain_name' => $domain, 'options' => 'All']);
        $this->processResponse($api, $result);

        if ($result->status() != 'OK') {
            return false;
        }

        $response = $result->response();

        return $this->Date->format(
            $format,
            isset($response["expiry_date"])
                ? $response["expiry_date"]
                : date('c')
        );
    }

    /**
     * Gets the domain name from the given service
     *
     * @param stdClass $service The service from which to extract the domain name
     * @return string The domain name associated with the service
     */
    public function getServiceDomain($service)
    {
        if (isset($service->fields)) {
            foreach ($service->fields as $service_field) {
                if ($service_field->key == 'domain-name') {
                    return $service_field->value;
                }
            }
        }

        return $this->getServiceName($service);
    }

    /**
     * Get a list of the TLDs supported by the registrar module
     *
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array A list of all TLDs supported by the registrar module
     */
    public function getTlds($module_row_id = null)
    {
        return Configure::get('Resellercampid.tlds');
    }

    /**
     * Get a list of the TLD prices
     *
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array A list of all TLDs and their pricing
     *    [tld => [currency => [year# => ['register' => price, 'transfer' => price, 'renew' => price]]]]
     */
    public function getTldPricing($module_row_id = null)
    {
        return $this->getFilteredTldPricing($module_row_id);
    }

    /**
     * Get a filtered list of the TLD prices
     *
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @param array $filters A list of criteria by which to filter fetched pricings including but not limited to:
     *
     *  - tlds A list of tlds for which to fetch pricings
     *  - currencies A list of currencies for which to fetch pricings
     *  - terms A list of terms for which to fetch pricings
     * @return array A list of all TLDs and their pricing
     *    [tld => [currency => [year# => ['register' => price, 'transfer' => price, 'renew' => price]]]]
     */
    public function getFilteredTldPricing($module_row_id = null, $filters = [])
    {
        Loader::loadModels($this, ['Currencies']);

        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == 'true');

        // Get all currencies
        $currencies = [];
        $company_currencies = $this->Currencies->getAll(Configure::get('Blesta.company_id'));
        foreach ($company_currencies as $currency) {
            $currencies[$currency->code] = $currency;
        }

        // Get TLD product mapping
        $maping_cache = Cache::fetchCache(
            'tlds_mapping',
            Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'resellercampid' . DS
        );
        if ($maping_cache) {
            $tld_mapping = unserialize(base64_decode($maping_cache));
        } else {
            $tld_mapping = $this->getTldProductMapping($api);
            $this->writeCache('tlds_mapping', $tld_mapping);
        }

        // Get TLD pricings
        $pricing_cache = Cache::fetchCache(
            'tlds_prices',
            Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'resellercampid' . DS
        );
        if ($pricing_cache) {
            $product_pricings = unserialize(base64_decode($pricing_cache));
        } else {
            $product_pricings = $this->getTldProductPricings($api);
            $this->writeCache('tlds_prices', $product_pricings);
        }

        // Get reseller details
        $reseller_cache = Cache::fetchCache(
            'reseller_details',
            Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'resellercampid' . DS
        );
        if ($reseller_cache) {
            $details = unserialize(base64_decode($reseller_cache));
        }
        if (!isset($details)) {
            $api->loadCommand('resellercampid_reseller');
            $reseller = new ResellercampidReseller($api);

            $response = $reseller->details($row->meta->reseller_id);
            $this->processResponse($api, $response);
            $details = $response->response();

            $this->writeCache('reseller_details', $details);
        }

        // Validate if the reseller currency exists in the company
        if (!isset($currencies[$details->parentselling_currency ?? 'USD'])) {
            $this->Input->setErrors(['currency' => ['not_exists' => Language::_('Resellercampid.!error.currency.not_exists', true)]]);

            return;
        }

        // Set TLD pricing
        $tld_yearly_prices = [];
        foreach ($product_pricings as $name => $pricing) {
            if (isset($tld_mapping[$name])) {
                foreach ($tld_mapping[$name] as $tld) {
                    $tld_name = strtolower($tld['label']);
                    $tld_yearly_prices[$tld_name] = [];

                    // Filter by 'tlds'
                    if (isset($filters['tlds']) && !in_array($tld_name, $filters['tlds'])) {
                        continue;
                    }

                    // Convert prices to all currencies
                    foreach ($currencies as $currency) {
                        // Filter by 'currencies'
                        if (isset($filters['currencies']) && !in_array($currency->code, $filters['currencies'])) {
                            continue;
                        }

                        $tld_yearly_prices[$tld_name][$currency->code] = [];
                        $register_price =  $this->Currencies->convert(
                            $pricing[0]['addnewdomain'],
                            $details->parentselling_currency ?? 'USD',
                            $currency->code,
                            Configure::get('Blesta.company_id')
                        );
                        $transfer_price = $this->Currencies->convert(
                            $pricing[0]['addtransferdomain'],
                            $details->parentselling_currency ?? 'USD',
                            $currency->code,
                            Configure::get('Blesta.company_id')
                        );
                        $renewal_price = $this->Currencies->convert(
                            $pricing[0]['renewdomain'],
                            $details->parentselling_currency ?? 'USD',
                            $currency->code,
                            Configure::get('Blesta.company_id')
                        );
                        foreach (range(1, 10) as $years) {
                            // Filter by 'terms'
                            if (isset($filters['terms']) && !in_array($years, $filters['terms'])) {
                                continue;
                            }

                            $tld_yearly_prices[$tld_name][$currency->code][$years] = [
                                'register' => $register_price * $years,
                                'transfer' => $transfer_price * $years,
                                'renew' => $renewal_price * $years
                            ];
                        }
                    }
                }
            }
        }

        return $tld_yearly_prices;
    }

    private function writeCache($cache_name, $content)
    {
        // Save the TLDs results to the cache
        if (Configure::get('Caching.on') && is_writable(CACHEDIR)) {
            try {
                Cache::writeCache(
                    $cache_name,
                    base64_encode(serialize($content)),
                    strtotime(Configure::get('Blesta.cache_length')) - time(),
                    Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'resellercampid' . DS
                );
            } catch (Exception $e) {
                // Write to cache failed, so disable caching
                Configure::set('Caching.on', false);
            }
        }
    }

    /**
     * Gets a list of TLDs organized by product
     *
     * @param ResellercampidApi $api
     * @return array A list of products and their associated TLDs
     */
    private function getTldProductMapping($api)
    {
        $api->loadCommand('resellercampid_products');
        $products = new ResellercampidProducts($api);
        $product_result = $products->getMappings();
        $this->processResponse($api, $product_result);

        // API request failed, return empty list
        if (trim($product_result->status()) !== 'OK') {
            return [];
        }

        // Format TLD list and organize by product
        $tld_mapping = [];
        $categories = $product_result->response();
        foreach ($categories as $product_name => $tlds) {
            $tld_mapping[$product_name] = $tlds;
        }

        return $tld_mapping;
    }

    /**
     * Gets a list of TLDs product pricings
     *
     * @param ResellercampidApi $api
     * @return stdClass A list of products and pricings
     */
    private function getTldProductPricings($api)
    {
        $api->loadCommand('resellercampid_products');
        $common = new ResellercampidProducts($api);
        $result = $common->getPricing();
        $this->processResponse($api, $result);

        if (trim($result->status()) !== 'OK') {
            return [];
        }
        $response = $result->response();

        return $response;
    }

    /**
     * Builds and returns the rules required to add/edit a module row
     *
     * @param array $vars An array of key/value data pairs
     * @return array An array of Input rules suitable for Input::setRules()
     */
    private function getRowRules (&$vars)
    {
        return array(
            'reseller_id' => array(
                'valid' => array(
                    'rule' => "isEmpty",
                    'negate' => true,
                    'message' => Language::_("Resellercampid.!error.reseller_id.valid", true)
                )
            ),
            'key' => array(
                'valid' => array(
                    'last' => true,
                    'rule' => "isEmpty",
                    'negate' => true,
                    'message' => Language::_("Resellercampid.!error.key.valid", true)
                ),
                'valid_connection' => array(
                    'rule' => array(array($this, "validateConnection"), $vars['reseller_id'], isset($vars['sandbox']) ? $vars['sandbox'] : "false"),
                    'message' => Language::_("Resellercampid.!error.key.valid_connection", true)
                )
            )
        );
    }

    /**
     * Validates that the given connection details are correct by attempting to check the availability of a domain
     *
     * @param string $key The API key
     * @param string $reseller_id The API reseller ID
     * @param string $sandbox "true" if this is a sandbox account, false otherwise
     * @return boolean True if the connection details are valid, false otherwise
     */
    public function validateConnection ($key, $reseller_id, $sandbox)
    {
        $api = $this->getApi($reseller_id, $key, $sandbox == "true");
        $api->loadCommand("resellercampid_domains");
        $domains = new ResellercampidDomains($api);

        // cek menggunakan domain .id
        $check = $domains->available(array("domain" => "resellercamp2017.id"));
        if ($check->status() == "OK") {
            return true;
        } else {
            // jika error, cek lagi menggunakan domain .com
            $check_lagi = $domains->available(array("domain" => "liquid2017.com"));
            if ($check_lagi->status() == "OK") {
                return true;
            }
        }

        return false;
    }

    /**
     * Initializes the ResellercampidApi and returns an instance of that object
     *
     * @param string $reseller_id The reseller ID to connect as
     * @param string $key The key to use when connecting
     * @param boolean $sandbox Whether or not to process in sandbox mode (for testing)
     * @return ResellercampidApi The ResellercampidApi instance
     */
    private function getApi ($reseller_id, $key, $sandbox)
    {
        Loader::load(dirname(__FILE__) . DS . "apis" . DS . "resellercampid_api.php");

        return new ResellercampidApi($reseller_id, $key, $sandbox);
    }

    /**
     * Process API response, setting an errors, and logging the request
     *
     * @param ResellercampidApi $api The resellercampid API object
     * @param ResellercampidResponse $response The resellercampid API response object
     */
    private function processResponse (ResellercampidApi $api, ResellercampidResponse $response)
    {
        $this->logRequest($api, $response);

        // Set errors, if any
        if ($response->status() != "OK") {
            $res_error = $response->errors();
            if (isset($res_error["message"])) {
                $errors = $res_error["message"];
            } else {
                $errors = "Errors is empty, please call resellercampid Customer Service";
            }

            $this->Input->setErrors(array('errors' => (array) $errors));
        }
    }

    /**
     * Logs the API request
     *
     * @param ResellercampidApi $api The resellercampid API object
     * @param ResellercampidResponse $response The resellercampid API response object
     */
    private function logRequest (ResellercampidApi $api, ResellercampidResponse $response)
    {
        $last_request = $api->lastRequest();

        $masks = array("api-key");
        foreach ($masks as $mask) {
            if (isset($last_request['args'][$mask]))
                $last_request['args'][$mask] = str_repeat("x", strlen($last_request['args'][$mask]));
        }

        $this->log($last_request['url'], serialize($last_request['args']), "input", true);
        $this->log($last_request['url'], $response->raw(), "output", $response->status() == "OK");
        $dbg_backtrace = array();
        foreach (debug_backtrace() as $key => $value) {
            if ($key >= 5) {
                continue;
            }
            $dbg_backtrace[$key] = $value;
            unset($dbg_backtrace[$key]["object"]);
        }
        $this->log($last_request['url'], json_encode($dbg_backtrace), "output", $response->status() == "OK");
    }

    /**
     * Returns the TLD of the given domain
     *
     * @param string $domain The domain to return the TLD from
     * @param boolean $top If true will return only the top TLD, else will return the first matched TLD from the list of TLDs
     * @return string The TLD of the domain
     */
    private function getTld ($domain, $top = false)
    {
        $tlds = Configure::get("Resellercampid.tlds");

        $domain = strtolower($domain);

        if (!$top) {
            foreach ($tlds as $tld) {
                if (substr($domain, -strlen($tld)) == $tld)
                    return $tld;
            }
        }
        return strrchr($domain, ".");
    }

    /**
     * Formats a phone number into +NNN.NNNNNNNNNN
     *
     * @param string $number The phone number
     * @param string $country The ISO 3166-1 alpha2 country code
     * @return string The number in +NNN.NNNNNNNNNN
     */
    private function formatPhone ($number, $country)
    {
        if (!isset($this->Contacts))
            Loader::loadModels($this, array("Contacts"));

        $return = $this->Contacts->intlNumber($number, $country, ".");
        return $return;
    }

    /**
     * Formats the contact ID for the given TLD and type
     *
     * @param int $contact_id The contact ID
     * @param string $tld The TLD being registered/transferred
     * @param string $type The contact type
     * @return int The contact ID to use
     */
    private function formatContact ($contact_id, $tld, $type)
    {
        $tlds = array();
        switch ($type) {
            case "admin":
            case "tech":
                $tlds = array(".eu", ".nz", ".ru", ".uk");
                break;
            case "billing":
                $tlds = array(".ca", ".eu", ".nl", ".nz", ".ru", ".uk");
                break;
        }
        if (in_array(strtolower($tld), $tlds))
            return -1;
        return $contact_id;
    }

    /**
     * Gets the Order Id of a Registered domain name.
     *
     * @param array $vars An array of input params including:
     * 	- domain_name The Registered domain name whose Order Id you want to know.
     * @return ResellercampidResponse
     */
    public function getorderid ($module_row_id, $domain)
    {

        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $api->loadCommand("resellercampid_domains");
        $domains = new ResellercampidDomains($api);


        $response = $domains->orderid(array('domain_name' => $domain));
        $this->processResponse($api, $response);

        if ($this->Input->errors())
            return;

        $order_id = null;
        $data_domains = $response->response();
        if (!empty($data_domains["domain_id"])) {
            $order_id = $data_domains["domain_id"];
        }

        return $order_id;
    }

    /**
     * Gets the Order Id of a Registered domain name.
     *
     * @param array $vars An array of input params including:
     * 	- domain-name The Registered domain name whose Order Id you want to know.
     * @return ResellercampidResponse
     */
    public function UpdateOrderID ($package, array $vars)
    {

        Loader::loadModels($this, array("Services"));

        $order_id = $this->getorderid($package->module_row, $vars['domain-name']);

        $this->Services->edit($vars['service-id'], array('domain-name' => $vars['domain-name'], 'order-id' => $order_id)); // performs service edit, and also calls YourModule::editService()

        if (($errors = $this->Services->errors())) {
            $this->parent->setMessage("error", $errors);
        }
        return true;
    }

    /**
     * Gets the Nameserver of a Registered domain name.
     *
     * @param array $vars An array of input params including:
     * 	- domain The Registered domain name whose Nameserver you want to know.
     * @return ResellercampidResponse
     */
    public function getDomainNameServers($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $api->loadCommand("resellercampid_domains");
        $domains = new ResellercampidDomains($api);
        $nameservers = [];
        $order_id = $this->getorderid($module_row_id, $domain);
        if ($order_id == null) {
            return false;
        } else{
            $result = $domains->details(array('domain_id' => $order_id, 'fields' => "ns"));
            $this->processResponse($api, $result);

            if ($result->status() != 'OK') {
                return false;
            }
            $response = $result->response();

            if (isset($response)) {
                foreach ($response as $ns => $nameserver) {
                    $nameservers[] = [
                        'url' => trim($nameserver),
                        'ips' => [gethostbyname(trim($nameserver))]
                    ];
                }
            }
        }
        return $nameservers;
    }

    /**
     * Set the Nameserver of a Registered domain name.
     *
     * @param array $vars An array of input params including:
     * 	- domain The Registered domain name whose Nameserver you want to know.
     * @return ResellercampidResponse
     */
    public function setDomainNameservers($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->reseller_id, $row->meta->key, $row->meta->sandbox == "true");
        $api->loadCommand("resellercampid_domains");
        $domains = new ResellercampidDomains($api);

        $order_id = $this->getorderid($module_row_id, $domain);

        $ns = array();
        foreach ($vars as $i => $nameserver) {
            if ($nameserver != "")
                $ns[] = $nameserver;
        }

        $ns_ = implode(",", $ns);
        $result = $domains->modifyNs(array('domain_id' => $order_id, 'ns' => $ns_));
        $this->processResponse($api, $result);

        $response = $result->response();

        return $response;
    }

}

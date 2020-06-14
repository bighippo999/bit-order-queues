<?php

/**
 * @link              http://www.blackicetrading.com
 * @since             1.0.0
 * @package           woocommerce_bit_order_queues
 *
 * @wordpress-plugin
 * Plugin Name:       WooCommerce BlackIce Order Queues
 * Plugin URI:        http://www.blackicetrading.com/plugin-bit-packingslips
 * Description:       Create queues from WooCommerce Attribute > Supplier
 * Version:           2.6.0
 * Author:            Dan
 * Author URI:        http://www.blackicetrading.com
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       woocommerce-bit-order-queues
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
        die;
}

if ( ! class_exists( 'WC_BIT_Order_Queues' ) ) {
 class WC_BIT_Order_Queues {

    public function __construct() {

    }

    public function init() {
        register_activation_hook( __FILE__, array( $this, 'plugin_activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'plugin_deactivate' ) );
        add_filter( 'plugin_action_links_' . plugin_basename(__FILE__),  array( $this, 'plugin_deactivate_warning' ) );

        add_filter( 'woocommerce_register_shop_order_post_statuses', array( $this, 'register_woocommerce_statuses' ) );
        add_filter( 'woocommerce_reports_order_statuses', array( $this, 'woocommerce_report_statuses' ) );
        add_filter( 'wc_order_statuses', array( $this, 'show_order_status_admin_dropdown' ) );
        add_filter( 'bulk_actions-edit-shop_order', array( $this, 'show_bulkaction_dropdown' ) );

        add_action( 'admin_notices', array( $this, 'attributes_warning' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'plugin_styles' ), 99 );
//        add_action( 'woocommerce_thankyou', array( $this, 'auto_assign_status' ) );
// disable thankyou page completion, replaced with ccheckout_order_processed and payment_complete.
////        add_action( 'woocommerce_thankyou', array( $this, 'schedule_or_run_auto_assign_status' ) );

//prep ready to change over from thankyou page to woocommerce order finished hooks.
        // Order processed happens for *all* orders/payment methods
        add_action('woocommerce_checkout_order_processed', array($this, 'woocommerce_checkout_order_processed'));
        // Payment complete happens only for some payment methods - ones that can be carried out over the Internet. For those, we don't want to move the order until they are completed. But when payment is complete, we always want to move.
        add_action('woocommerce_payment_complete', array($this, 'woocommerce_payment_complete'));

        // register our hook for the scheduler to pass back jobs to the plugin.
        add_action( 'bit_order_queues_schedule_event', array($this, 'auto_assign_status') );
        // register our hook for the scheduler to check the processing queue.
        add_action( 'bit_order_processing_schedule_event', array($this, 'check_processing_queue') );

        // deactive the automatic print on payment.
        add_filter('woocommerce_print_orders_print_on_payment_complete', '__return_false');
        add_filter('woocommerce_print_orders_print_order_upon_processed', '__return_false');
    }

    /**
     * The code that runs during plugin activation.
     */
    public function plugin_activate() {
        if ( !class_exists( 'WooCommerce' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( __( 'Please install and Activate WooCommerce.', 'woocommerce-addon-slug' ), 'Plugin dependency check', array( 'back_link' => true ) );
        }
        $result = as_schedule_recurring_action( time()+300, 300, 'bit_order_processing_schedule_event', array(), "Order Status Update" );
    }

    /**
     * The code that runs during plugin deactivation.
     */
    public function plugin_deactivate() {
        $result = as_unschedule_all_actions( 'bit_order_processing_schedule_event', array(), "Order Status Update" );
    }

   /**
    * Add Deactrivation warning to Plugins Page.
    */
   public function plugin_deactivate_warning( $links ) {
       $links[] = '<p>Re-assign existing orders before Deactivating.</p>';
       return $links;
   }

   /**
    * Display Attributes Editing Warning.
    */
   public function attributes_warning() {
        if (!taxonomy_exists('pa_supplier')) {
            $html = '<div class="notice notice-info is-dismissible">';
            $html .= '<p>WooCommerce BlackIce Order Queues:  You must create a "Supplier" Attribute in Products>Attributes.</p>';
            $html .= '</div>';

            echo $html;
        }

       global $pagenow;
       if ( $pagenow == 'edit-tags.php' && isset($_GET['taxonomy']) && isset($_GET['post_type']) && ($_GET['taxonomy'] == 'pa_supplier') && ($_GET['post_type'] == 'product') ) {
           $html = '<div class="notice notice-warning">';
           $html .= '<p>You MUST reassign existing orders before editing/changing slug information.<br/>';
           $html .= 'Failiure to do so may result in missing orders.</p>';
           $html .= '</div>';

           echo $html;
       } elseif ( $pagenow == 'edit.php' && isset($_GET['post_type']) && isset($_GET['page']) && ($_GET['post_type'] == 'product') && ($_GET['page'] == 'product_attributes') ) {
           $html = '<div class="notice notice-error">';
           $html .= '<p>DO NOT DELETE the Supplier Attribute.<br/>';
           $html .= 'Deletion may result in missing orders</p>';
           $html .= '</div>';

           echo $html;
       }
   }

   /**
    * Add the CSS for order status colours.
    */
   public function plugin_styles() {
       wp_enqueue_style( 'wc_bit_order_statuses', plugins_url('style.css', __FILE__) );
   }

   /**
    *
    */
   public function woocommerce_report_statuses( $order_status ) {
       // get the new statuses that we're registering.
       $registered_wc_statuses = $this->register_woocommerce_statuses(array());
       // strip the wc- and add them to the order_status that reports will look through.
       foreach( $registered_wc_statuses as $registered=>$value ) {
           $order_status[] = str_replace('wc-', '', $registered);
       }

       return $order_status;
   }

   /**
    * Register WooCommerce Statuses for each Supplier in Attributes>Suppliers
    */
   public function register_woocommerce_statuses( $order_statuses ) {
       if (taxonomy_exists('pa_supplier')) {
           $options = array('hide_empty' => false);
           $terms = get_terms('pa_supplier', $options);

           $new_statuses = [
                   "-rexp" => "(Ready to Export)",
                   "-aimp" => "(Awaiting Import)",
                   "-adis" => "(Awaiting Dispatch)",
                   "-comp" => "(Dispatched)",
                   "-qery" => "(Query)",
           ];
           $ignore_slugs = [
                   "bit",
                   "virtual-item",
           ];

           $order_statuses['wc-bit-rexp'] = array(
               'label'                     => _x( 'BIT (Processing)', 'Order Status', 'woocommerce' ),
               'public'                    => false,
               'exclude_from_search'       => false,
               'show_in_admin_all_list'    => true,
               'show_in_admin_status_list' => true,
               'label_count'               => _n_noop( 'BIT (Processing) <span class="count">(%s)</span>', 'BIT (Processing) <span class="count">(%s)</span>', 'woocommerce' ),
           );
           $order_statuses['wc-bit-multi'] = array(
               'label'                     => _x( 'Multiple Suppliers (To Process)', 'Order Status', 'woocommerce' ),
               'public'                    => false,
               'exclude_from_search'       => false,
               'show_in_admin_all_list'    => true,
               'show_in_admin_status_list' => true,
               'label_count'               => _n_noop( 'Multiple Suppleir (To Process) <span class="count">(%s)</span>', 'Multiple Suppliers (Process) <span class="count">(%s)</span>', 'woocommerce' ),
           );
           foreach ( $terms as $term ) {
               $name = $term->name;
               $slug = $term->slug;
               if ( in_array($slug, $ignore_slugs) ) {
                   continue;
               }
               // Status must start with "wc-" and be <=18 characters.
               foreach ( $new_statuses as $statusslug=>$statusname ) {
                   $newname = $name . " " . $statusname;
                   $preslug = "wc-";
                   $endslug = $statusslug;
                   $lenavailable = 18 - strlen($preslug) - strlen($endslug);
                   $cutslug = substr( $slug, 0, $lenavailable );
                   $newslug = $preslug . $cutslug . $endslug;
                   $order_statuses[$newslug] = array(
                     'label'                     => _x( $newname, 'Order status', 'woocommerce' ),
                     'public'                    => false,
                     'exclude_from_search'       => false,
                     'show_in_admin_all_list'    => true,
                     'show_in_admin_status_list' => true,
                     'label_count'               => _n_noop( $newname . ' <span class="count">(%s)</span>', $newname . ' <span class="count">(%s)</span>', 'woocommerce' ),
                   );
               }
           }
       } else {

       }
   return $order_statuses;

   }

   /**
    * For Each Supplier in Attributes>Suppliers show Order Status in Admin and in the Dropdown on Single Order
    */
   public function show_order_status_admin_dropdown( $order_statuses ) {
      if (taxonomy_exists('pa_supplier')) {
          $options = array('hide_empty' => false);
          $terms = get_terms('pa_supplier', $options);

           $new_statuses = [
                   "-rexp" => "(Ready to Export)",
                   "-aimp" => "(Awaiting Import)",
                   "-adis" => "(Awaiting Dispatch)",
                   "-comp" => "(Dispatched)",
                   "-qery" => "(Query)",
           ];
           $ignore_slugs = [
                   "bit",
                   "virtual-item",
           ];

          $order_statuses['wc-bit-rexp'] = _x( 'BIT (Processing)', 'Order status', 'woocommerce' );
          $order_statuses['wc-bit-multi'] = _x( 'Multiple Suppliers (To Process)', 'Order status', 'woocommerce' );

          foreach ( $terms as $term ) {
              $name = $term->name;
              $slug = $term->slug;
              if ( in_array($slug, $ignore_slugs) ) {
                  continue;
              }
              // Status must start with "wc-" and be <=18 characters.
              foreach ( $new_statuses as $statusslug=>$statusname ) {
                  $newname = $name . " " . $statusname;
                  $preslug = "wc-";
                  $endslug = $statusslug;
                  $lenavailable = 18 - strlen($preslug) - strlen($endslug);
                  $cutslug = substr( $slug, 0, $lenavailable );
                  $newslug = $preslug . $cutslug . $endslug;
                  $order_statuses[$newslug] = _x( $newname, 'Order status', 'woocommerce' );
              }
          }
      } else {

      }
   return $order_statuses;
   }

   /**
    * For Each Supplier in Attributes>Suppliers show Order Status in the Bulk Action Dropdown
    */
   public function show_bulkaction_dropdown( $bulk_actions ) {
      if (taxonomy_exists('pa_supplier')) {
          $options = array('hide_empty' => false);
          $terms = get_terms('pa_supplier', $options);

           $new_statuses = [
                   "-rexp" => "(Ready to Export)",
                   "-adis" => "(Awaiting Dispatch)",
                   "-qery" => "(Query)",
           ];
           $ignore_slugs = [
                   "bit",
                   "virtual-item",
           ];
           $ignore_adis = [
                   "gf",
                   "ppe",
           ];

           foreach ( $terms as $term ) {
               $name = $term->name;
               $slug = $term->slug;
               if ( in_array($slug, $ignore_slugs) ) {
                   continue;
               }
               // Status must start with "wc-" and be <=18 characters.
               foreach ( $new_statuses as $statusslug=>$statusname ) {
                   if (( $statusslug == "-adis" ) && ( in_array($slug, $ignore_adis) )) {
                       continue;
                   }
                   $newname = $name . " " . $statusname;
                   $endslug = $statusslug;
                   $lenavailable = 18 - 3 - strlen($endslug);
                   $cutslug = substr( $slug, 0, $lenavailable );
                   $baction = "mark_" . $cutslug . $endslug;
                   $bulk_actions[$baction] = 'Change status to  ' . $newname;
               }
           }
      } else {

      }
   return $bulk_actions;
   }

   /**
    * Order processed happens for *all* orders/payment methods.
    */
   public function woocommerce_checkout_order_processed( $order_id ) {
       $order = is_a($order_id_or_order, 'WC_Order') ? $order_id_or_order : wc_get_order($order_id_or_order);
       $order_id = is_callable(array($order, 'get_id')) ? $order->get_id() : $order->id;

       $payment_method = is_callable(array($order, 'get_payment_method')) ? $order->get_payment_method() : $order->payment_method;

       // 'cop' is from https://wordpress.org/plugins/wc-cash-on-pickup/
       // 'pis' is from https://wordpress.org/plugins/woocommerce-pay-in-store-gateway/
       // 'other_payment' is from https://wordpress.org/plugins/woocommerce-other-payment-gateway/
       if ($payment_method == 'cod' || $payment_method == 'adminoverride' || $payment_method == 'cheque' || $payment_method == 'bacs' || $payment_method == 'cop' || $payment_method == 'pis' || $payment_method == 'other_payment') {
          $this->schedule_or_run_auto_assign_status( $order_id );
       }
   }

   /**
    * Payment complete happens only for some payment methods - ones that can be carried out over the Internet. For those, we don't
    * want to move the order until they are completed. But when payment is complete, we always want to move.
    */
   public function woocommerce_payment_complete( $order_id ) {
       $this->schedule_or_run_auto_assign_status( $order_id );
   }

   /**
    * Schedule auto_assign_status instead of run immediately, to decrease impact on thank you page for cmrs.
    */
   public function schedule_or_run_auto_assign_status( $order_id ) {
       // If Action Scheduler is available, queue the auto_assign_status action, otheriwse run it now.
       if ( function_exists( 'as_enqueue_async_action' ) ) {
           as_enqueue_async_action( 'bit_order_queues_schedule_event', array( $order_id ), "Order Status Update" );
       } else {
           $this->auto_assign_status( $order_id );
       }
   }

   /**
    * Check the processing queue for orders and schedule to process each one.
    * This cleans up any orders left in processing. Limit to 5 at a time.
    */
   public function check_processing_queue() {
      $args = array(
          'status' => 'processing',
          'limit' => 5,
          'orderby' => 'date',
          'order' => 'ASC',
          'return' => 'ids',
       );
       $orders = wc_get_orders( $args );
       foreach ( $orders as $order ) {
           $this->schedule_or_run_auto_assign_status( $order );
       }
   }

   /**
    * Automatically assign Order to -rexp IF the order only contains items dispatchable by 1 supplier.
    */
   public function auto_assign_status( $order_id ) {
       if ( ! $order_id ) {
           return;
       }

       $order = wc_get_order( $order_id );
       $order_items = $order->get_items();
       $suppliers = [];
       $abort_assign = false;
       $reevaluate = [];
       $product_titles = [];

           foreach ( $order_items as $item_id => $item ) {
               $product = $item->get_product();
               $product_type = $product->get_type();
               switch ($product_type) {
                   case 'simple':
                       $product_id = $product->get_id();
                       $product_titles[] = $product->get_name();
                       break;
                   case 'variation':
                       $product_id = $product->get_parent_id();
                       $product_titles[] = $product->get_name();
                       break;
                   default:
                       $product_id = $product->get_id();
                       $abort_assign = true;
                       break;
               } //end switch
               $productattrib = wc_get_product_terms( $product_id, 'pa_supplier', array( 'fields' => 'slugs' ) );
               // check the return is an array and only 1 supplier.
               if ( ( is_array($productattrib) || is_object($productattrib) ) && (count($productattrib) == 1 ) ) {
                   foreach ($productattrib as $supplier) {
                       $suppliers[] = $supplier;
                   }
               // if more than 1 supplier add to reevaluate the product later once all items have been checked for single supplier.
               } elseif ( ( is_array($productattrib) || is_object($productattrib) ) && (count($productattrib) > 1 ) ) {
                   $reevaluate[] = $product_id;
               // abort the loop and assignment if there's a problem.
               } else {
                   $abort_assign = true;
                   break;
               } //end if
           } //end for each

       // clean the suppliers list of duplicates.
       $suppliers = array_unique( $suppliers );
       // if there's only 1 supplier so far and it's not BIT and we aren't aborting...
       if ( ( count($suppliers) == 1 ) && ( $abort_assign == false ) ) {
           // now go reevaluate the other items that have multiple suppliers and see if they can be fulfilled by the one we have.
           foreach ( $reevaluate as $product_id ) {
               $productattrib = wc_get_product_terms( $product_id, 'pa_supplier', array( 'fields' => 'slugs' ) );
               if ( is_array($productattrib) || is_object($productattrib) ) {
                   $assigntoexisting = false;
                   if ( in_array( $suppliers[0], $productattrib ) ) {
                       // the existing supplier can take this item.
                       $suppliers[] = $supplier;
                   } elseif ( ! in_array( $suppliers[0], $productattrib ) ) {
                       // No the 1 supplier can not take this item. So we'll add to the suppliers list.
                       foreach ( $productattrib as $attrib ) {
                           $suppliers[] = $attrib;
                       }
                   } // end if
               } else {
                   $abort_assign=true;
               } //end if
           } //end foreach
       } elseif ( ( count($suppliers) == 0 ) && ( $abort_assign == false ) ) {
           // no suppliers have currently been assigned. To give additional items to.
           // we haven't abort. So the items are still able to be sent to multiple suppliers.
           // compare the titles and add suppliers for mug = gf and patches = bit.
           // then reevaluate the items.
           foreach ( $product_titles as $title ) {
               if ( stripos($title, 'patch') !== false ) {
                   // title has patch, set suppliers to bit only.
                   $suppliers[] = 'bit';
               } elseif ( stripos($title, 'mug') !== false ) {
                   // title has mug, set supplier to gf only.
                   $suppliers[] = 'gf';
               } else {
                   // it's not a mug or a patch, so process as normal.
               } // end if
            } //end foreach
            // now reevaluate all the items we could assign to see if they will go to BIT and or GF.
            // If both are present the next step will fail for manual processing. If only 1 the items will be assigned to that and progress.
            foreach ( $reevaluate as $product_id ) {
                $productattrib = wc_get_product_terms( $product_id, 'pa_supplier', array( 'fields' => 'slugs' ) );
                if ( is_array($productattrib) || is_object($productattrib) ) {
                    if ( in_array( $suppliers[0], $productattrib) ) {
                        // the existing supplier can take this item.
                    } elseif ( ! in_array( $suppliers[0], $productattrib ) ) {
                        // No the 1 supplier can not take this item. So we'll add to the suppliers list.
                        foreach ( $productattrib as $attrib ) {
                            $suppliers[] = $attrib;
                        }
                    }
                } else {
                    $abort_assign=true;
                } //end if
            } //end foreach
       } // end if

       $suppliers = array_unique( $suppliers );
       // we recheck the supplier count and make sure we're not aborting after the reevaluation.
       if ( ( count($suppliers) == 1) && ( $abort_assign == false ) ) {
           $slug = $suppliers[0];
           $endslug = "-rexp";
           $lenavailable = 18 - 3 - strlen($endslug);
           $newslug = substr($slug, 0, $lenavailable) . $endslug;
           if ( $order->get_status() == 'processing' ) {
               $order->update_status( $newslug );
               if ( $newslug == 'bit-rexp' ) {
                   global $woocommerce_ext_printorders;
                   $woocommerce_ext_printorders->woocommerce_print_order_go($order_id);
               }
           }
       } elseif ( ( count($suppliers) > 1) && ( $abort_assign == false ) ) {
           $newslug = 'wc-bit-multi';
           if ( $order->get_status() == 'processing' ) {
               $order->update_status( $newslug );
               // print the order because it's going to multiple suppliers.
               global $woocommerce_ext_printorders;
               $woocommerce_ext_printorders->woocommerce_print_order_go($order_id);
           }
       } // end if
   } // end function

 }
 $GLOBALS['WC_BIT_Order_Queues'] = new WC_BIT_Order_Queues();
 $GLOBALS['WC_BIT_Order_Queues']->init();
}

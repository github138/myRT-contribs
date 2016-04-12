<?php

/*
 *	Rack 3D View
 *		!Experimental!
 *
 *
 */

/*
 * INSTALL
 *
 *	put rack3dview.php in plugins folder
 *
 *	create plugins/rack3dview folder
 *	place babylon.js file in this folder
 *
 *	babylon.js - http://cdn.babylonjs.com/2-3/babylon.js
 *
 *
 *	goto Rackspace page -> 3D View tab -> choose rows -> OK
 *
 */

/*
 *	TODO
 *		cleanup code!
 *		optimize 3D model
 *		rack heights/widths
 *
 *		container support
 */

/**
 * The newest version of this plugin can be found at:
 *
 * https://github.com/github138/myRT-contribs/tree/develop-0.20.x
 *
 */

/*
 * * (c)2016 Maik Ehinger <m.ehinger@ltur.de>
 */

$tab['rackspace']['rack3dview'] = '3D View';
$tabhandler['rackspace']['rack3dview'] = 'rack3dview_tabhandler';

$ajaxhandler['r3dv_data'] = 'rack3dview_ajax_data';

function rack3dview_tabhandler()
{
	if(isset($_POST['rows']))
	{
		$rows = $_POST['rows'];
		rack3dview_display($rows);
		return;
	}

	echo "<form id=rows method=POST>";
	echo "<table>";
	foreach (listCells ('row') as $row_id => $rowInfo)
	{
		/* location from renderRackspace() */
			$location_id = $rowInfo['location_id'];
			$locationIdx = 0;
			// contains location names in the form of 'grandparent parent child', used for sorting
			$locationTree = '';
			// contains location names as well as links
			$hrefLocationTree = '';
			while ($location_id)
			{
				if ($locationIdx == 20)
				{
					showWarning ("Warning: There is likely a circular reference in the location tree.  Investigate location ${location_id}.");
					break;
				}
				$parentLocation = spotEntity ('location', $location_id);
				$locationTree = sprintf ('%s %s', $parentLocation['name'], $locationTree);
				$hrefLocationTree = "&raquo; <a href='" .
					makeHref(array('page'=>'location', 'location_id'=>$parentLocation['id'])) .
					"'>${parentLocation['name']}</a> " .
					$hrefLocationTree;
				$location_id = $parentLocation['parent_id'];
				$locationIdx++;
			}
			$hrefLocationTree = substr ($hrefLocationTree, 8);

		echo "<tr><td>$hrefLocationTree</td>";
		echo "<th class=tdleft><a href='".makeHref(array('page'=>'row', 'row_id'=>$row_id))."'>${rowInfo['name']}</a></th>";
		echo "<td><input type=checkbox name=rows[$row_id] value=$row_id></td>";
		echo "</tr>";
	}
	echo "<tr><td></td><td></td><td><input type=submit value=OK></td></tr>";
	echo "</table>";
	echo "</form>";
} // tabhandler

function rack3dview_display($rows)
{

	$rows = implode(",",$rows);

	echo (<<<HTMLEND
   <style>
      html, body {
         overflow: hidden;
         width: 100%;
         height: 100%;
         margin: 0;
         padding: 0;
      }
      #renderCanvas {
         width: 100%;
         height: 100%;
         touch-action: none;
      }
   </style>
   <script src="?module=chrome&uri=rack3dview/babylon.js"></script>
<!--   <script src="?module=chrome&uri=rack3dview/hand.js"></script> -->
<!--   <script src="?module=chrome&uri=rack3dview/cannon.js"></script> --><!-- optional physics engine -->
<!-- <script src="?module=chrome&uri=rack3dview/Oimo.js"></script>  New physics engine -->
   <canvas id="renderCanvas"></canvas>
<div id="debug" style="overflow: scroll;height:200px;width:100%"></div>
   <script type="text/javascript">

	var rdata = null;
$.ajax({
        type: "POST",
        url: "{$_SERVER['PHP_SELF']}?module=ajax&ac=r3dv_data&json=json",
        data: {
                rows: "$rows"
              },
        dataTye: 'json',
	async: false,
        error: function(){ alert("Error loading"); },
        success: function(data) {
					rdata = JSON.parse(data);
					if(rdata.debug)
						$('#debug').html(rdata.debug);
				}
});





      // Get the canvas element from our HTML below
      var canvas = document.querySelector("#renderCanvas");
      // Load the BABYLON 3D engine
      var engine = new BABYLON.Engine(canvas, true);
      // -------------------------------------------------------------

	var scale = 0.001;

      // Here begins a function that we will 'call' just after it's built
      var createScene = function () {
         // Now create a basic Babylon Scene object
         var scene = new BABYLON.Scene(engine);
         // Change the scene background color to green.
        // scene.clearColor = new BABYLON.Color3(0.1, 0.1, 0.1);
	//scene.forceWireframe = true;
	//scene.lightsEnabled = false;
         // This creates and positions a free camera name, alpha, beta, radius, target, scene
         //var camera = new BABYLON.ArcRotateCamera("camera1", (-90*Math.PI) / 180 , (90*Math.PI) / 180, 5000 * scale, new BABYLON.Vector3(0, 0, 0), scene);
         var camera = new BABYLON.ArcRotateCamera("camera1", (0*Math.PI) / 180 , (0*Math.PI) / 180, 5000 * scale, new BABYLON.Vector3(0, 0, 0), scene);
	camera.wheelPrecision = 1/(scale * 10); // slow down mouse wheel speed x10
         //var camera = new BABYLON.ArcRotateCamera("camera1", 0, 0, 5000 * scale, new BABYLON.Vector3(0, 0, 0), scene);
         // This targets the camera to scene origin
         //camera.setTarget(BABYLON.Vector3.Zero());
         // This attaches the camera to the canvas
         camera.attachControl(canvas, false);
         // This creates a light, aiming 0,1,0 - to the sky.
         var light = new BABYLON.HemisphericLight("light1", new BABYLON.Vector3(0, 1, 1), scene);
         var light2 = new BABYLON.HemisphericLight("light2", new BABYLON.Vector3(0, 1, -1), scene);
         // Dim the light a small amount
         light.intensity = 0.8;
         light2.intensity = 0.8;

	var myColors = new Array(
				new BABYLON.Color4(1,1,1,1), // back
				new BABYLON.Color4(1,1,1,1), // front
				new BABYLON.Color4(1,1,1,1), // left
				new BABYLON.Color4(1,1,1,1), // right
				new BABYLON.Color4(1,1,1,1), // top
				new BABYLON.Color4(1,1,1,1)  // bottom
				);

	var objtypecolors = {
			// default
			0: new Array(
				new BABYLON.Color4(1,1,1,1), // back
				new BABYLON.Color4(1,1,1,1), // front
				new BABYLON.Color4(1,1,1,1), // left
				new BABYLON.Color4(1,1,1,1), // right
				new BABYLON.Color4(1,1,1,1), // top
				new BABYLON.Color4(1,1,1,1)  // bottom
				),
			// server
			4: new Array(
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(0,1,0,0),
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(0,1,0,0),
                                new BABYLON.Color4(0,0,0,0)
				),
			// network switch
			8: new Array(
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(0,0,1,0),
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(0,0,1,0),
                                new BABYLON.Color4(0,0,0,0)
				),
			// patchpanel
			9: new Array(
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(1,0,0,0),
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(1,0,0,0),
                                new BABYLON.Color4(0,0,0,0)
                                ),
			// cableoganizer
			10: new Array(
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(1,1,0,0),
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(1,1,0,0),
                                new BABYLON.Color4(0,0,0,0)
                                ),
			// kvm switch
			445: new Array(
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(0,1,1,0),
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(0,0,0,0),
                                new BABYLON.Color4(0,1,1,0),
                                new BABYLON.Color4(0,0,0,0)
				),
			};

	var scale3 = new BABYLON.Vector3(scale, scale, scale);

	var material1 = new BABYLON.StandardMaterial("texture1", scene);
	material1.alpha = 0.1;

	var material3 = new BABYLON.StandardMaterial("texture3", scene);
	material3.alpha = 0.3;

	var material0 = new BABYLON.StandardMaterial("texture0", scene);
	material0.backFaceCulling = false;

	var multimat1 = new BABYLON.MultiMaterial("multi1", scene);
	multimat1.subMaterials.push(material1);
	multimat1.subMaterials.push(material3);
	multimat1.subMaterials.push(material0);

	function createLabel(id, label, name, color, size, labelsize)
	{
		if(name === undefined)
			name = '';

		if(color === undefined)
			color ="white";

		if(size === undefined)
			size = 482;

		if(labelsize === undefined)
			labelsize = 28;

		var dynamicTexture = new BABYLON.DynamicTexture("DynamicTexture"+id, 512, scene, true);
		dynamicTexture.hasAlpha = true;
		namesize = 16;

		if(label != null )
			dynamicTexture.drawText(label, 0, labelsize, "bold "+labelsize+"px Arial", color , "transparent", true);
		else
		{
			labelsize = 0;
			namesize = 24;
		}

		if(name != '' )
			dynamicTexture.drawText(name, size/2, labelsize+namesize, "bold "+namesize+"px Arial", color , "transparent", true);

		var plane = new BABYLON.Mesh.CreatePlane("TextPlane"+id, size, scene, true);
		plane.material = new BABYLON.StandardMaterial("TextPlaneMaterial", scene);
		plane.material.backFaceCulling = false;
		plane.material.specularColor = new BABYLON.Color3(0, 0, 0);
		plane.material.diffuseTexture = dynamicTexture;
	//	plane.isVisible = false;
		return plane;

	};

	function createrack(name, rtname, maxunits, height, width, depth, maxdepth19, width19) {
		// RACK
		if(maxunits === undefined)
			maxunits = 42;

		if(height === undefined)
			height = 2000;

		if(width === undefined)
			width = 800;

		if(depth === undefined)
			depth = 1000;

		if(maxdepth19 === undefined)
			maxdepth19 = 800;

		if(width19 === undefined)
			width19 = 482.6;

		this.rack19frame = BABYLON.MeshBuilder.CreateBox(name + '19', {height: maxunits * 44.45 -1, width: width19 -1, depth: maxdepth19 -1, faceColors: myColors}, scene);
		this.rack19frame.position = new BABYLON.Vector3(0,0,0);
		this.rack19frame.showBoundingBox = false;
		this.rack19frame.scaling = scale3;
		this.rack19frame.material = material3;

		var label = createLabel(name, rtname, '', "white", 200, 100);
		label.parent = this.rack19frame;
		label.position = new BABYLON.Vector3(0,(height-200)/2, (maxdepth19/-2));

		this.rackframe = BABYLON.MeshBuilder.CreateBox(name, {height: height, width: width, depth: depth, faceColors: myColors}, scene);
		this.rackframe.material = material3;
		this.rackframe.showBoundingBox = false;
		this.rackframe.parent = this.rack19frame;
		//this.rackframe.isVisible = false;

		this.rackframe.material = multimat1;
		//box.subMeshes.push(new BABYLON.SubMesh(0,0,4,0,6, box )); // front
		//box.subMeshes.push(new BABYLON.SubMesh(1,4,4,6,6, box )); // right
		//box.subMeshes.push(new BABYLON.SubMesh(2,8,4,12,6, box )); // back
		//box.subMeshes.push(new BABYLON.SubMesh(1,12,4,18,6, box )); // left
		//box.subMeshes.push(new BABYLON.SubMesh(1,16,4,24,6, box )); // top
		this.rackframe.subMeshes.push(new BABYLON.SubMesh(2,20,4,30,6, this.rackframe )); // bottom

	if(0)
	{
		var lines = BABYLON.Mesh.CreateLines("lines", [
				new BABYLON.Vector3(0, 0, 0),
				new BABYLON.Vector3(20 + 482.6, 0, 0),
				new BABYLON.Vector3(20, 0, 0),
				new BABYLON.Vector3(20, -6.35, 0),
				new BABYLON.Vector3(10, -6.35, 0),
				new BABYLON.Vector3(20, -6.35, 0),
				new BABYLON.Vector3(20, -15.88 - 6.35, 0),
				new BABYLON.Vector3(10, -15.88 - 6.35, 0),
				new BABYLON.Vector3(20, -15.88 - 6.35, 0),
				new BABYLON.Vector3(20, -15.88 * 2 - 6.35, 0),
				new BABYLON.Vector3(10, -15.88 * 2 - 6.35, 0),
				new BABYLON.Vector3(20, -15.88 * 2 - 6.35, 0),
				new BABYLON.Vector3(20, -15.88 * 2 - 12.7, 0),
				new BABYLON.Vector3(0, -15.88 * 2 - 12.7, 0),
		], scene);

		lines.parent = this.rack19frame;
		lines.position = new BABYLON.Vector3((482.6 / -2) - 20, (maxunits/2) * 44.45, maxdepth19 / -2);
		lines.isVisible = false;


		for(u = 2; u<=maxunits;u++)
		{
			var lines1 = lines.clone();
			lines1.parent = this.rack19frame;
			lines1.position.y = (maxunits/2) * 44.45 - ((u-1) * 44.45);

			var text = this.Text(u,40);
			text.parent = lines1;
			text.position.x = 10;
			text.position.y = -44.45/2;

		}

		var text = this.Text("1");
		text.parent = lines;
		text.position.x = 10;
		text.position.y = 44.45/-2;
	} // TEXT

		this.addObject = function(name, rtname, rtlabel, position, units, type , locpos, locdepth) {

			if(type === undefined)
				type = 0;

			if(locpos === undefined)
				locpos = 0;

			if(locdepth === undefined)
				locdepth = 3;

			var faceColors = (objtypecolors[type] ? objtypecolors[type] : objtypecolors[0]);

			depth19 = (locdepth == 3 ? maxdepth19 : locdepth * (maxdepth19/3));

			if(position < 0)
				depth19 = 2;

			var server = BABYLON.MeshBuilder.CreateBox(name, {height: units * 44.45 - 2, width: width19, depth: depth19, faceColors: faceColors}, scene);
			server.showBoundingBox = false;
			server.parent = this.rack19frame;

			var label = createLabel(name, rtlabel, rtname, "black", 482);
			label.parent = server;
			label.position = new BABYLON.Vector3(0,(482 - 44.45)/ -2,-depth19/2 - 1);

			var pos = (((maxunits)/2) * -44.45) + (position-1) * 44.45 + ((units * 44.45) / 2);

			//console.log(name + " " + position + "  " + ( maxunits - position ) + " - " + pos + " u: " + units);

			depth19start = (maxdepth19 - depth19)/-2;

			if( locpos == 1)
				depth19start = depth19start + maxdepth19/3;
			else
				if( locpos == 2 )
					depth19start = depth19start + ((maxdepth19/3) * 2);

			//console.log("obj: " + locpos + "  " + locdepth + " -- " + depth19start + "  " + depth19);

			server.position = new BABYLON.Vector3(0, pos,depth19start);

			if( locpos != 0 )
				server.rotation.y = Math.PI; // 180 degree
		};

		this.addObject3 = function(name, position3, units3) {
			var position = (position3/3);
			var units = (units3/3);

			this.addObject(name, position, units);

		};

		this.position = function(x,y,z) {
			this.rack19frame.position = new BABYLON.Vector3(x * scale,(y + ((height/2) - 1000)) * scale ,z * scale);
		};

		this.rotation = function(degx,degy,degz) {
			this.rack19frame.rotation = new BABYLON.Vector3((degx * Math.PI) / 180, (degy * Math.PI) / 180,(degz * Math.PI) / 180);
		}

	}

	//rack19frame.position = new BABYLON.Vector3((2000 / 2) * scale,10,0);

	var r = new Array();
	rowcount = 0;
	rowpos = 0;
	rdata.rows.forEach(function(row) {
			rowcount++;

			rowpos = (rowcount - 1) * -4000;

			var rowlabel = createLabel(rowcount, row.name, '', "white", 500*scale, 60);
			rowlabel.position = new BABYLON.Vector3(((800/-2)+(500/2)-60*2)*scale,0,rowpos * scale);
			rowlabel.rotation = new BABYLON.Vector3(0,0,(90*Math.PI)/180);

			rackcount = 0;
			if(row.racks)
			row.racks.forEach(function(rackobj) {
				//alert(rackobj.rack_id);
				var r = new createrack("rack_id_" + rackobj.rack_id, rackobj.name, rackobj.height, (rackobj.height == 47 ? 2200 : 2000));
				rackcount++;
				r.position((rackcount - 1) * 800,0, rowpos);

				if(((rowcount) % 2) == 0)
					r.rotation(0,180,0);

				zerou = 0;
				rackobj.objects.forEach( function(obj) {

								obj.fullunits.forEach( function(unit) {
									r.addObject(obj.object_id, obj.name, obj.label, unit.id, unit.count, obj.objtype_id);
								});

								obj.partitialunits.forEach( function(unit) {
									r.addObject(obj.object_id, obj.name, obj.label, unit.id , 1, obj.objtype_id, unit.locpos, unit.locdepth);
								});

								if(obj.zerounit)
								{
									zerou++;
									r.addObject(obj.object_id, obj.name, obj.label, -zerou-2, 1, obj.objtype_id, 0, 1);
								}
							});

				}
			);
		}
	);

         // Let's try our built-in 'ground' shape. Params: name, width, depth, subdivisions, scene
        // var ground = BABYLON.Mesh.CreateGround("ground1", 6, 6, 2, scene);
         // Leave this function
         camera.setTarget(new BABYLON.Vector3(0,0, (rowpos/2) * scale));
         return scene;
      }; // End of createScene function
      // -------------------------------------------------------------
      // Now, call the createScene function that you just finished creating
      var scene = createScene();
      // Register a render loop to repeatedly render the scene
      engine.runRenderLoop(function () {
         scene.render();
      });
      // Watch for browser/canvas resize events
      window.addEventListener("resize", function () {
         engine.resize();
      });
   </script>
HTMLEND
); // echo
} // display

function rack3dview_ajax_data()
{
	ob_start();

	if(isset($_POST['rows']))
		$rack_rows = explode(",",$_POST['rows']);
	else
		return;

	//r3dv_var_dump_html(listCells ('row'));

	//$location = spotEntity('location', 2);
	//amplifyCell($location);

	//foreach($location['rows'] as $row_id => $row)
//	foreach(array(4,15,29,43) as $row_id)
	foreach($rack_rows as $row_id)
	{
		$row = spotEntity('row', $row_id);
		amplifyCell($row);

		$rows[$row_id]['row_id'] = $row_id;
		$rows[$row_id]['name'] = $row['name'];

		foreach($row['racks'] as $rack_id => $rack)
	//	foreach(array(16) as $rack_id)
		{
			//echo "$rack_id<br>";
			$rack = spotEntity('rack', $rack_id);
			amplifyCell($rack);

			$objects = array();
			$last_obj_id = NULL;

			for($u = 1;$u <= $rack['height']; $u++)
			{
				//echo "$u: ";

				foreach($rack[$u] as $atom_id => $atom)
				{
					if ( $atom['state'] != 'F' )
					{
						//echo $atom['object_id'];
						if($last_obj_id == $atom['object_id'])
							$objects[$atom['object_id']]['unit'][$u][$atom_id] = $atom['state'];
						else
						{
							$objects[$atom['object_id']]['object_id'] =  $atom['object_id'];
							$objects[$atom['object_id']]['fullunits'] = array();
							$objects[$atom['object_id']]['partitialunits'] = array();
							$objects[$atom['object_id']]['unit'][$u][$atom_id] = $atom['state'];
						}

						$last_obj_id = $atom['object_id'];
						//echo "  ";
					}
				} // atom


				//echo "<br>";
			} // unit

			$zeroUObjects = getChildren ($rack, 'object');
			foreach($zeroUObjects as $object)
			{
				$objects[$object['id']]['zerounit'] = 1;
				$objects[$object['id']]['object_id'] =  $object['id'];
				$objects[$object['id']]['fullunits'] = array();
				$objects[$object['id']]['partitialunits'] = array();
				$objects[$object['id']]['unit'] = array();
			}

			foreach($objects as $object_id => $object)
			{
				$objectdata = spotEntity('object', $object_id);

			//	r3dv_var_dump_html($object);
			//	r3dv_var_dump_html($objectdata);

				$objects[$object_id]['objtype_id'] = $objectdata['objtype_id'];
				$objects[$object_id]['name'] = $objectdata['name'];
				$objects[$object_id]['label'] = $objectdata['label'];

				$first_unit = null;
				$lat_fullunit = null;
				foreach($object['unit'] as $u => $atoms)
				{
					if(count($atoms) == 3)
					{

						if($first_unit !== null && ($last_fullunit + 1 == $u))
						{
							$objects[$object_id]['fullunits'][$first_unit]['count']++;
						}
						else
						{
							$first_unit = $u;
							$objects[$object_id]['fullunits'][$u] = array('id' => $u, 'count' => 1 );
						}

						$last_fullunit = $u;
					}
					else
					{
						$first_unit = null;

						//r3dv_var_dump_html($atoms);

						$locpos = 0;
						$locdepth = 1;

						if(isset($atoms[1]))
						{
							if(isset($atoms[0]))
								$locdepth = 2;
							else
								$locpos = 1;
						}

						if(isset($atoms[2]))
						{
							if(isset($atoms[1]))
								$locdepth = 2;
							else
							{
								$locpos  = 2;

								if(isset($atoms[0]))
									$objects[$object_id]['partitialunits'][] = array( 'id' => $u, 'locpos' => 0, 'locdepth' => 1);
							}
						}

						$objects[$object_id]['partitialunits'][] = array( 'id' => $u, 'locpos' => $locpos, 'locdepth' => $locdepth);

						//r3dv_var_dump_html($objects[$object_id]);
					}

				}
				unset($objects[$object_id]['unit']);
				$objects[$object_id]['fullunits'] = array_values($objects[$object_id]['fullunits']);
			} // objects


			$rows[$row_id]['racks'][] = array('rack_id' => $rack_id, 'height' => $rack['height'], 'name' => $rack['name'], 'objects' => array_values($objects));
		} // rack
	} // row

	$msgs = ob_get_contents();
	ob_end_clean();

	$ret = array();

	if($msgs)
		$ret['debug'] = $msgs;

	$ret['rows'] = array_values($rows);

	echo json_encode($ret);
	exit;
} // tabhandler


function r3dv_var_dump_html($var)
{
	echo "<pre>";
	var_dump($var);
	echo "</pre>";
}

?>

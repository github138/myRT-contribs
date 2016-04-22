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
 *	only for RT <= 0.20.10
 *	place babylon.js file in wwwroot/js folder
 *  	remove line "<script src="?module=chrome&uri=rack3dview/babylon.js"></script>" somewhere around line 161
 *	uncomment line <!-- for RT 0.20.10 --> <script src="?module=chrome&uri=js/babylon.js"></script>
 *
 *
 *	for RT >= 0.20.11
 *	create plugins/rack3dview folder
 *	place babylon.js file in this folder
 *
 *
 *	babylon.js - http://cdn.babylonjs.com/2-3/babylon.js
 *
 *	2.4.0-alpha - better mouse control (recommended)
 *	https://github.com/BabylonJS/Babylon.js/raw/master/dist/preview%20release/babylon.js
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
 *		https://blog.raananweber.com/2015/09/03/scene-optimization-in-babylon-js-introduction/
 *		optimize label sizes / cutoffs
 *		optimize zero-u container handling
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

$debug = 0;

function rack3dview_tabhandler()
{
	global $debug;

	if($debug)
	{
	rack3dview_display(array(15, 43, 704));
	//rack3dview_display(array(704));
	return;
	}

	if(isset($_POST['racks']))
	{
		$racks = $_POST['racks'];
		rack3dview_display(array_keys($racks));
		return;
	}

	addJS(<<<ENDJS
		function selectRows(elem) {
			var loc = elem.name.slice(4,-1);
			$("input[value='loc_"+loc+"']").attr("checked", (elem.checked ? "checked" : ""));
			$("input[value='loc_"+loc+"']").each( function() { selectRacks(this); });
		}

		function selectRacks(elem) {
			var row = elem.name.slice(5,-1);
			$("input[value='row_"+row+"']").attr("checked", (elem.checked ? "checked" : ""));
		}
ENDJS
,true);

	echo "<form id=rows method=POST>";
	echo "<table>";

	$locations = array();

	foreach (listCells ('row') as $row_id => $rowInfo)
	{
			if(!$debug)
				ob_start(); // Notice: Undefined index: height

			amplifyCell($rowInfo);

			if(!$debug)
				ob_end_clean(); // Notice: Undefined index: height

		/* location from renderRackspace() */
			$location_id = $rowInfo['location_id'];

			if(!isset($locations[$location_id]))
			{
				$locations[$location_id] = 1;
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

				echo "<tr></tr><td colspan=5><hr></td></tr>";
				echo "<tr><td><input onclick=\"selectRows(this);\" type=checkbox name=loc[{$rowInfo['location_id']}] value={$rowInfo['location_id']}></td>";
				echo "<td>$hrefLocationTree</td></tr>";

			}

		echo "<tr><td colspan=2></td>";
		echo "<td><input onclick=\"selectRacks(this);\" type=checkbox name=rows[$row_id] value=loc_{$rowInfo['location_id']}></td>";
		echo "<th class=tdleft><a href='".makeHref(array('page'=>'row', 'row_id'=>$row_id))."'>${rowInfo['name']}</a></th>";
		echo "</tr>";

		foreach($rowInfo['racks'] as $rack_id => $rack)
		{
			echo "<tr><td colspan=4></td><td><input type=checkbox name=racks[$rack_id] value=row_{$row_id}></td>";
			echo "<td>{$rack['name']}</td></tr>";
		}
	}
	echo "<tr><td colspan=3></td><td><input type=submit value=OK></td></tr>";
	echo "</table>";
	echo "</form>";
} // tabhandler

function rack3dview_display($racks)
{

	$racks = implode(",",$racks);

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
<!-- for RT 0.20.10 --><!--   <script src="?module=chrome&uri=js/babylon.js"></script> -->
<!--   <script src="?module=chrome&uri=rack3dview/hand.js"></script> -->
<!--   <script src="?module=chrome&uri=rack3dview/cannon.js"></script> --><!-- optional physics engine -->
<!-- <script src="?module=chrome&uri=rack3dview/Oimo.js"></script>  New physics engine -->
   <canvas id="renderCanvas"></canvas>
<div id="debug" style="overflow: scroll;height:200px;width:100%"></div>
   <script type="text/javascript">

	"use strict";

	var rdata = null;
$.ajax({
        type: "POST",
        url: "{$_SERVER['PHP_SELF']}?module=ajax&ac=r3dv_data&json=json",
        data: {
                racks: "$racks"
              },
        dataTye: 'json',
	async: false,
        error: function(){ alert("Error loading"); },
        success: function(data) {
					rdata = JSON.parse(data);
				}
});


$(document).ready(function () {
	if(rdata.debug)
		$('#debug').html(rdata.debug);

	if(rdata.msgs)
		rdata.msgs.forEach( function(msg) {
			$('.msgbar').append("<div class=msg_warning>"+msg+"</div>");
		});

	if (BABYLON.Engine.isSupported()) {

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
	camera.wheelPrecision = 1/(scale * 5); // slow down mouse wheel speed x5
	camera.angularSensibilityX = camera.angularSensibilityY = 1000;
	camera.zoomOnFactor = 1/(scale);
	camera.panningSensibility = 1/(scale);
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

	var RackColors = new Array(
				new BABYLON.Color4(1,1,1,1), // back
				new BABYLON.Color4(1,1,1,1), // front
				new BABYLON.Color4(1,1,1,1), // left
				new BABYLON.Color4(1,1,1,1), // right
				new BABYLON.Color4(1,1,1,1), // top
				new BABYLON.Color4(1,1,1,1)  // bottom
				);

	var RackColorsProblems = new Array(
				new BABYLON.Color4(1,0,0,1), // back
				new BABYLON.Color4(1,0,0,1), // front
				new BABYLON.Color4(1,0,0,1), // left
				new BABYLON.Color4(1,0,0,1), // right
				new BABYLON.Color4(1,0,0,1), // top
				new BABYLON.Color4(1,0,0,1)  // bottom
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
	material1.freeze();

	var material3 = new BABYLON.StandardMaterial("texture3", scene);
	material3.alpha = 0.3;
	material3.freeze();

	var material0 = new BABYLON.StandardMaterial("texture0", scene);
	material0.backFaceCulling = false;
	material0.freeze();

	var multimat1 = new BABYLON.MultiMaterial("multi1", scene);
	multimat1.subMaterials.push(material1);
	multimat1.subMaterials.push(material3);
	multimat1.subMaterials.push(material0);


	/* modified function from babylon js
	 * add dyntex parameter
	 * add text align x = 'right'
	 */
	function drawTextalign(dyntex, text, x, y, font, color, clearColor, invertY, update) {
		if(update === undefined)
			update = true;

            var size = dyntex.getSize();
            if (clearColor) {
                dyntex._context.fillStyle = clearColor;
                dyntex._context.fillRect(0, 0, size.width, size.height);
            }

            dyntex._context.font = font;
            if (x === null) {
                var textSize = dyntex._context.measureText(text);
                x = (size.width - textSize.width) / 2;
            }

		if(x === 'right')
		{
			var textSize = dyntex._context.measureText(text);
			x = (size.width - textSize.width);
		}

            dyntex._context.fillStyle = color;
            dyntex._context.fillText(text, x, y);

            if (update) {
                dyntex.update(invertY);
            }
        }

	function createLabelMaterial(objdata, colors, align, fontsize)
	{
		if(colors.label === undefined)
			colors.label ="black";

		if(colors.name === undefined)
			colors.name ="black";

		if(align === undefined)
			align = {label:0, name:'right'};

		var size = objdata.labelsize;

		if(fontsize === undefined)
		{
			if( objdata.label == null )
				fontsize = {label: 0, name: size.height*3/5};
			else
				fontsize = {label: size.height * 3/5, name: size.height*2/5};
		}

		if(fontsize.label === undefined)
			fontsize.label = 0;

		var dynamicTexture = new BABYLON.DynamicTexture("DynamicTexture"+objdata.id, size, scene, true);
		dynamicTexture.hasAlpha = true;
	//	dynamicTexture.coordinatesMode = BABYLON.Texture.PLANAR_MODE;

		var texsize = dynamicTexture.getSize();

		var textpos = texsize.height/2 + fontsize.label/2;

		if( objdata.name != '')
			textpos -=  fontsize.name/2;

		if(objdata.label !== null )
			dynamicTexture.drawText(objdata.label, align.label, textpos, "bold "+fontsize.label+"px Arial", colors.label , "transparent", true);

		//var textwidth = dynamicTexture._context.measureText(label).width;

		if(objdata.name != '' )
			drawTextalign(dynamicTexture, objdata.name, align.name, textpos + fontsize.name, "bold "+fontsize.name+"px Arial", colors.name , "transparent", true);

		var material = new BABYLON.StandardMaterial("TextPlaneMaterial", scene);
		material.freeze();
		material.backFaceCulling = false;
		material.diffuseTexture = dynamicTexture;
		material.diffuseTexture.vScale = objdata.options.height/texsize.height;
		material.diffuseTexture.uScale = 1;
		var vOffsetLabel = (texsize.height - size.height) / texsize.height / 4; // center within size.height
		var vOffsetObject = (objdata.options.height - size.height)/texsize.height;

		if(objdata.children)
			material.diffuseTexture.vOffset = vOffsetLabel - vOffsetObject;
		else
			material.diffuseTexture.vOffset = vOffsetLabel - vOffsetObject/2;

		return material;

	}; // createLabelMaterial

	function makeObject(objdata, parent) {
			var type = objdata.objtype_id;
			var faceColors = (objtypecolors[type] ? objtypecolors[type].slice() : objtypecolors[0].slice()); //copy array

			var labelcolors = {label: "black", name: "black"};
			if(objdata.has_problems != 'no')
			{
				//labelcolors = {label: "red", name: "red"};
				faceColors[1] = new BABYLON.Color3.FromHexString("#dd0048");
			}

			var width = objdata.unit.width;
			var height = objdata.unit.height - 2;

			if(parent.layout !== undefined)
			{
				width -= 2;
				if(parent.layout == 'V')
				{
					var h = height;
					height = width;
					width = h;
				}
			}

			objdata.options = {width: width, height: height, depth: objdata.unit.depth, faceColors: faceColors};

			var object = BABYLON.MeshBuilder.CreateBox(objdata.object_id, objdata.options, scene);

			if( parent !== undefined)
				object.parent = parent.object;

			var labelMaterial = createLabelMaterial(objdata, labelcolors);

			var MultiMaterial = new BABYLON.MultiMaterial("lmm"+objdata.id, scene);
			MultiMaterial.subMaterials.push(material0);
			MultiMaterial.subMaterials.push(labelMaterial);

			object.material = MultiMaterial;
			object.subMeshes.push(new BABYLON.SubMesh(1,4,4,6,6, object )); // -z

			if(parent.layout !== undefined)
			{
				// rotate child
				if(parent.layout == 'V')
					object.rotation = new BABYLON.Vector3(0,0,(-90*Math.PI)/180);
			}

			object.convertToUnIndexedMesh();
			objdata.object = object;

			if(objdata.children)
			{
				objdata.children.forEach( function(child) {
					addSlot(child, objdata);
				});
			}

			return object;

		} //addObject

		function addRTObject(objdata, rackobj)
		{

			if(objdata.unit.locdepth === undefined)
				objdata.unit.locdepth = 3;

			var depth19 = (objdata.unit.locdepth == 3 ? rackobj.maxdepth19 : objdata.unit.locdepth * (rackobj.maxdepth19/3));

			//zeroU
			if(objdata.unit.id < 0)
				depth19 = 2;

			objdata.unit.height = objdata.unit.count * rackobj.unit_height;
			objdata.unit.width = rackobj.width19;
			objdata.unit.depth = depth19;

			objdata.labelsize = {width: objdata.unit.width, height: (objdata.unit.height > rackobj.unit_height ? rackobj.unit_height : objdata.unit.height)};

			var object = makeObject(objdata, rackobj);

			var pos = (((rackobj.maxunits)/2) * -rackobj.unit_height) + (objdata.unit.id-1) * rackobj.unit_height + ((objdata.unit.count * rackobj.unit_height) / 2);

			var depth19start = (rackobj.maxdepth19 - depth19)/-2;

			if(objdata.unit.locpos === undefined)
				objdata.unit.locpos = 0;

			if( objdata.unit.locpos == 1)
				depth19start = depth19start + rackobj.maxdepth19/3;
			else
				if( objdata.unit.locpos == 2 )
					depth19start = depth19start + ((rackobj.maxdepth19/3) * 2);

			object.position = new BABYLON.Vector3(0, pos,depth19start);

			if( objdata.unit.locpos != 0 )
				object.rotation.y = Math.PI; // 180 degree
		} // addRTObject

		function addSlot(objdata, parent)
		{
			var rows = parent.rows;
			var cols = parent.cols;
			var slot = objdata.slot;

			objdata.unit = {
					width: parent.unit.width / cols,
					height: (parent.unit.height - parent.labelsize.height) / rows,
					depth: 2
					};

			if(parent.layout == 'V')
				objdata.labelsize = {height: objdata.unit.width, width: objdata.unit.height};
			else
				objdata.labelsize = objdata.unit;

			var object = makeObject(objdata, parent);

			var row = Math.floor((slot-1) / cols);
			var col = Math.floor((slot-1) % cols);

			object.position = new BABYLON.Vector3( ((parent.unit.width - objdata.unit.width) / -2) + col * objdata.unit.width, ((parent.unit.height - objdata.unit.height)/2) - row * objdata.unit.height - parent.labelsize.height,(parent.unit.depth - objdata.unit.depth) / -2 - 2);
		}

	function createRack(objdata) {
		// RACK
		if(objdata.maxunits === undefined)
			objdata.maxunits = 42;

		if(objdata.width19 === undefined)
			objdata.unit_height = 44.45;

		if(objdata.height === undefined)
			objdata.height = (objdata.maxunits == 47 ? 2200 : 2000);

		if(objdata.width === undefined)
			objdata.width = 800;

		if(objdata.depth === undefined)
			objdata.depth = 1000;

		if(objdata.maxdepth19 === undefined)
			objdata.maxdepth19 = 800;

		if(objdata.width19 === undefined)
			objdata.width19 = 482.6;

		var rack19frame = BABYLON.MeshBuilder.CreateBox('rack19_' + objdata.rack_id, {height: objdata.maxunits * objdata.unit_height - 3, width: objdata.width19 -1, depth: objdata.maxdepth19 -1, faceColors: RackColors}, scene);
		rack19frame.position = new BABYLON.Vector3(0,0,0);
		rack19frame.showBoundingBox = false;
		rack19frame.scaling = scale3;
		rack19frame.material = material3;

		objdata.labelsize = {width:400, height:100};
		var labelMaterial = createLabelMaterial({ id: objdata.rack_id, label: objdata.name, name:'' , options: { width: 200, height: 100}, labelsize:objdata.labelsize}, {label:"white"}, {label: 0, name: null}); //, {label:100});

		var label = new BABYLON.MeshBuilder.CreatePlane("racklabel"+objdata.rack_id,  objdata.labelsize, scene);
		label.material = labelMaterial;
		label.material.backFaceCulling = false;
		//label.showBoundingBox = true;

		label.parent = rack19frame;
		label.position = new BABYLON.Vector3(0,objdata.height/2+objdata.labelsize.height, (objdata.maxdepth19/-2));

		var options = {height: objdata.height, width: objdata.width, depth: objdata.depth};

		if(objdata.has_problems != 'no')
			options.faceColors = RackColorsProblems;
		else
			options.faceColors = RackColors;

		var rackframe = BABYLON.MeshBuilder.CreateBox('rf'+objdata.id, options, scene);
		rackframe.material = material3;
		rackframe.showBoundingBox = false;
		rackframe.parent = rack19frame;
		//rackframe.isVisible = false;

		rackframe.material = multimat1;
		//box.subMeshes.push(new BABYLON.SubMesh(0,0,4,0,6, box )); // front
		//box.subMeshes.push(new BABYLON.SubMesh(1,4,4,6,6, box )); // right
		//box.subMeshes.push(new BABYLON.SubMesh(2,8,4,12,6, box )); // back
		//box.subMeshes.push(new BABYLON.SubMesh(1,12,4,18,6, box )); // left
		//box.subMeshes.push(new BABYLON.SubMesh(1,16,4,24,6, box )); // top
		rackframe.subMeshes.push(new BABYLON.SubMesh(2,20,4,30,6, rackframe )); // bottom

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

		lines.parent = rack19frame;
		lines.position = new BABYLON.Vector3((482.6 / -2) - 20, (objdata.maxunits/2) * 44.45, objdata.maxdepth19 / -2);
		lines.isVisible = false;


		for(u = 2; u<=maxunits;u++)
		{
			var lines1 = lines.createInstance("lines"+u); // interfer
			lines1.parent = rack19frame;
			lines1.position.y = (objdata.maxunits/2) * 44.45 - ((u-1) * 44.45);

			var text = makeText(u,40);
			text.parent = lines1;
			text.position.x = 10;
			text.position.y = -44.45/2;

		}

		var text = makeText("1");
		text.parent = lines;
		text.position.x = 10;
		text.position.y = 44.45/-2;
	} // TEXT

		objdata.object = rack19frame;

		return rack19frame;
	} //createRack

	//rack19frame.position = new BABYLON.Vector3((2000 / 2) * scale,10,0);

	var rowlabel;
	var rowcount = 0;
	var rowpos = 0;
	rdata.rows.forEach(function(row) {
			rowcount++;

			rowpos = (rowcount - 1) * -4000;

			var labelsize = {width: 2000, height: 200};
			var labelMaterial = createLabelMaterial({ id:rowcount, label:row.name, name:'' , options: labelsize, labelsize: labelsize}, {label:"white"}, {label: 0, name: null}, {label:200, name:50});

			rowlabel = new BABYLON.MeshBuilder.CreatePlane("TextPlane"+rowcount,  labelsize, scene);

			rowlabel.material = labelMaterial;
			rowlabel.material.backFaceCulling = false;
			//rowlabel.showBoundingBox = true;

			rowlabel.scaling = scale3;
			rowlabel.position = new BABYLON.Vector3(((800/-2) - labelsize.height) * scale,labelsize.width/2 * scale,rowpos * scale);
			rowlabel.rotation = new BABYLON.Vector3(0,0,(90*Math.PI)/180);

			var rackcount = 0;
			if(row.racks)
			row.racks.forEach(function(rackobj) {
				//alert(rackobj.rack_id);
				var r = new createRack(rackobj);

				rackcount++;
				r.position = new BABYLON.Vector3((rackcount-1) * rackobj.width * scale,(((rackobj.height/2))) * scale ,rowpos * scale);

				if(((rowcount) % 2) == 0)
					r.rotation = new BABYLON.Vector3(0, Math.PI,0); // 180 degree

				var zerou = 0;
				rackobj.objects.forEach( function(obj) {

					if(obj.fullunits)
					obj.fullunits.forEach( function(unit) {
						obj.unit = unit;
						addRTObject(obj, rackobj)
					});

					if(obj.partitialunits)
					obj.partitialunits.forEach( function(unit) {
						unit.count = 1;
						obj.unit = unit;
						addRTObject(obj, rackobj);
					});

					if(obj.zerounit)
					{
						zerou++;
						obj.unit = {id: -zerou - 2, count:1, locpos: 0, locdepth: 0};
						addRTObject(obj, rackobj);
					}

				});

			});
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

      var octree = scene.createOrUpdateSelectionOctree()

      // Register a render loop to repeatedly render the scene
      engine.runRenderLoop(function () {
         scene.render();
      });
      // Watch for browser/canvas resize events
      window.addEventListener("resize", function () {
         engine.resize();
      });
} // engine.issupported
}); // document.ready()
   </script>
HTMLEND
); // echo
} // display

function rack3dview_ajax_data()
{
	ob_start();

	if(isset($_POST['racks']))
		$racks = explode(",",$_POST['racks']);
	else
		return;

	$msgs = array();
	$rows = array();

		foreach($racks as $rack_id)
		{
			$rack = spotEntity('rack', $rack_id);
			$row_id = $rack['row_id'];

			if(!isset($rows[$row_id]))
			{
				$row = spotEntity('row', $row_id);
				amplifyCell($row);

				$rows[$row_id] = array();
				$rows[$row_id]['row_id'] = $row_id;
				$rows[$row_id]['name'] = $row['name'];
			}

			//echo "$rack_id<br>";
			$rackproblems = 'no';
			$rack = spotEntity('rack', $rack_id);
			amplifyCell($rack);

			if($rack['has_problems'] != 'no')
				$rackproblems = $rack['has_problems'];

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
				if(!isset($objects[$object['id']]))
				{
					$objects[$object['id']]['object_id'] =  $object['id'];
					$objects[$object['id']]['fullunits'] = array();
					$objects[$object['id']]['partitialunits'] = array();
					$objects[$object['id']]['unit'] = array();
				}

				$objects[$object['id']]['zerounit'] = 1;
			}

			foreach($objects as $object_id => $object)
			{
				$objectData = spotEntity('object', $object_id);

				// ------------contains children------------------
				if (in_array ($objectData['objtype_id'], array (1502,1503))) // server chassis, network chassis
				{
					$numrows = null;
					$numcols = 1;
					$layout = 'H';
					$numslots = null;

					$needsslots = false;

					$attrData = getAttrValues ($object_id);
					if (isset ($attrData[2])) // HW type
					{
						extractLayout ($attrData[2]);
						if (isset ($attrData[2]['rows']))
						{
							$numrows = $attrData[2]['rows'];
							$numcols = $attrData[2]['cols'];
							$layout = $attrData[2]['layout'];
							$numslots = $numrows * $numcols;
						}
					}

					$objectChildren = getChildren ($objectData, 'object');
					if (count($objectChildren) > 0)
					{
						$objects[$object_id]['children'] = array();
						foreach ($objectChildren as $childData)
						{
							$child_id = $childData['id'];

							$objects[$object_id]['children'][$child_id]['object_id'] = $child_id;
							$objects[$object_id]['children'][$child_id]['objtype_id'] = $childData['objtype_id'];
							$objects[$object_id]['children'][$child_id]['name'] = $childData['name'];
							$objects[$object_id]['children'][$child_id]['label'] = $childData['label'];
							$objects[$object_id]['children'][$child_id]['id'] = $child_id;
							$objects[$object_id]['children'][$child_id]['has_problems'] = $childData['has_problems'];
							if($childData['has_problems'] != 'no')
								$rackproblems = $childData['has_problems'];

							$attrData = getAttrValues ($childData['id']);
							//r3dv_var_dump_html($attrData);
							if (isset ($attrData['28'])) // slot number
							{
								$slot = $attrData['28']['value'];
								if (preg_match ('/\d+/', $slot, $matches))
									$slot = $matches[0];

								$needsslots = true;

								if($numslots === null)
								{
										$slot = null;
								}
								else
									if($slot > $numslots)
									{
										$msgs[] = "slot > slots for ".$childData['name']." in ".$objectData['name']." not displayed!";
										$slot = null;
									}

								if($slot)
									$objects[$object_id]['children'][$child_id]['slot'] = $slot;
							}
						}

						if($numslots === null && $needsslots)
							$msgs[] = "No slots ( rows/cols)  for ".$objectData['name']." not displaying children!";

						$objects[$object_id]['rows'] = $numrows;
						$objects[$object_id]['cols'] = $numcols;
						$objects[$object_id]['layout'] = $layout;
						$objects[$object_id]['slots'] = $numslots;
						$objects[$object_id]['children'] = array_values($objects[$object_id]['children']);

					} // object children
				}
				//----------- end children -----------------------------

				$objects[$object_id]['objtype_id'] = $objectData['objtype_id'];
				$objects[$object_id]['name'] = $objectData['name'];
				$objects[$object_id]['label'] = $objectData['label'];
				$objects[$object_id]['has_problems'] = $objectData['has_problems'];

				if($objectData['has_problems'] != 'no')
					$rackproblems = $objectData['has_problems'];

				$first_unit = null;
				$last_fullunit = null;

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


			$rows[$row_id]['racks'][] = array('rack_id' => $rack_id, 'maxunits' => $rack['height'], 'name' => $rack['name'], 'has_problems' => $rackproblems, 'objects' => array_values($objects));
		} // rack

	$debugtxt = ob_get_contents();
	ob_end_clean();

	$ret = array();

	if($debugtxt)
		$ret['debug'] = $debugtxt;

	if($msgs)
		$ret['msgs'] = $msgs;

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
